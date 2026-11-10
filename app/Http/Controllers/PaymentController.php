<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\InvoiceBalance;
use App\Actions\Payments\PayPalClient;
use App\Actions\Payments\PayPalGatewayException;
use App\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        $payments = Payment::query()
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['invoice.customer'])
            ->latest()
            ->get();

        // Totals per status and currency, summed in whole cents so the numbers are exact.
        $totals = $payments
            ->groupBy(fn (Payment $payment): string => $payment->status.'|'.$payment->currency)
            ->map(function ($group): array {
                $first = $group->first();
                $cents = $group->sum(fn (Payment $payment): int => (int) round(((float) $payment->amount) * 100));

                return [
                    'status' => $first->status,
                    'currency' => $first->currency,
                    'total' => number_format($cents / 100, 2, '.', ''),
                    'count' => $group->count(),
                ];
            })
            ->values();

        return Inertia::render('Payments/Index', [
            'payments' => $payments->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'paypal_id' => $payment->paypal_capture_id ?? $payment->paypal_order_id,
                'customer' => $payment->invoice?->customer?->name,
                'invoice' => $payment->invoice?->invoice_number,
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ])->values(),
            'totals' => $totals,
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $payment = Payment::query()
            ->whereKey($payment->getKey())
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['invoice.customer'])
            ->firstOrFail();

        return response()->json([
            'payment' => $payment,
        ]);
    }

    public function createPaypalOrder(
        Request $request,
        Invoice $invoice,
        PayPalClient $paypal,
        InvoiceBalance $balance
    ): JsonResponse {
        if (! $paypal->isConfigured()) {
            return response()->json([
                'message' => 'PayPal Sandbox credentials are not configured.',
            ], 503);
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'amount' => ['nullable', 'string', 'max:32'],
        ]);
        $user = $request->user();
        $invoiceId = $invoice->getKey();

        $payment = DB::transaction(function () use ($user, $invoiceId, $validated, $balance): Payment {
            $invoice = $user->invoices()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== 'approved') {
                throw ValidationException::withMessages([
                    'invoice' => 'Only approved invoices with a balance can be paid.',
                ]);
            }

            if (! in_array($invoice->currency, config('services.paypal.supported_currencies', []), true)) {
                throw ValidationException::withMessages([
                    'currency' => "PayPal does not support {$invoice->currency}. No currency conversion was applied.",
                ]);
            }

            $existingPayment = Payment::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existingPayment !== null) {
                abort_unless($existingPayment->invoice_id === $invoice->id, 404);

                return $existingPayment->load('invoice');
            }

            if ($invoice->payments()->whereIn('status', ['pending', 'processing'])->exists()) {
                abort(409, 'Another payment checkout is already pending for this invoice.');
            }

            $remainingMinor = $balance->remainingMinor($invoice, true);

            if ($remainingMinor <= 0) {
                throw ValidationException::withMessages([
                    'invoice' => 'This invoice has no remaining balance.',
                ]);
            }

            $amount = $validated['amount'] ?? Currency::format($remainingMinor, $invoice->currency);

            try {
                $amountMinor = Currency::minor($amount, $invoice->currency);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'amount' => 'Enter an amount using the precision supported by the invoice currency.',
                ]);
            }

            if ($amountMinor <= 0 || $amountMinor > $remainingMinor) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero and no more than the remaining balance.',
                ]);
            }

            return $invoice->payments()->create([
                'idempotency_key' => $validated['idempotency_key'],
                'paypal_capture_request_id' => (string) Str::uuid(),
                'amount' => Currency::format($amountMinor, $invoice->currency),
                'currency' => $invoice->currency,
                'status' => 'pending',
            ])->load('invoice');
        });

        if ($payment->paypal_order_id !== null) {
            return $this->orderResponse($payment);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'This checkout attempt has failed. Start a new checkout with a new idempotency key.',
            ], 409);
        }

        try {
            $payment = DB::transaction(function () use ($payment, $paypal): Payment {
                $lockedPayment = Payment::query()
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedPayment->paypal_order_id !== null) {
                    return $lockedPayment;
                }

                if ($lockedPayment->status !== 'pending') {
                    abort(409, 'This checkout attempt has failed. Start a new checkout with a new idempotency key.');
                }

                $lockedPayment->load('invoice');
                $paypalOrder = $paypal->createOrder($lockedPayment, $lockedPayment->invoice);
                $orderId = $paypalOrder['id'] ?? null;
                $approvalLink = collect($paypalOrder['links'] ?? [])
                    ->first(fn (mixed $link): bool => is_array($link) && ($link['rel'] ?? null) === 'approve');
                $approvalUrl = is_array($approvalLink) ? ($approvalLink['href'] ?? null) : null;

                if (! is_string($orderId) || $orderId === '' || ! is_string($approvalUrl) || $approvalUrl === '') {
                    throw new PayPalGatewayException(
                        'PayPal returned an incomplete checkout response. Retry with the same idempotency key.',
                        502
                    );
                }

                $lockedPayment->update([
                    'paypal_order_id' => $orderId,
                    'paypal_approval_url' => $approvalUrl,
                ]);

                return $lockedPayment->refresh();
            });
        } catch (PayPalGatewayException $exception) {
            Log::warning('PayPal order creation failed', [
                'payment_id' => $payment->id,
                'status' => $exception->httpStatus,
            ]);

            if ($exception->httpStatus === 422) {
                Payment::query()
                    ->whereKey($payment->getKey())
                    ->where('status', 'pending')
                    ->whereNull('paypal_order_id')
                    ->update(['status' => 'failed']);
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        return $this->orderResponse($payment->refresh());
    }

    public function capturePaypalOrder(
        Request $request,
        string $orderId,
        PayPalClient $paypal,
        InvoiceBalance $balance
    ): JsonResponse {
        if (! $paypal->isConfigured()) {
            return response()->json([
                'message' => 'PayPal Sandbox credentials are not configured.',
            ], 503);
        }

        try {
            $result = DB::transaction(function () use ($request, $orderId, $paypal, $balance): array {
                $payment = Payment::query()
                    ->where('paypal_order_id', $orderId)
                    ->whereHas('invoice', fn (Builder $query) => $query
                        ->where('user_id', $request->user()->id))
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($payment->status === 'completed') {
                    $invoice = $request->user()->invoices()->findOrFail($payment->invoice_id);

                    return ['payment' => $payment, 'invoice' => $invoice];
                }

                if ($payment->status !== 'pending') {
                    abort(409, 'This payment cannot be captured in its current state.');
                }

                $invoice = $request->user()->invoices()
                    ->whereKey($payment->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($invoice->status !== 'approved') {
                    abort(409, 'This invoice is no longer available for payment.');
                }

                $captureResponse = $paypal->captureOrder($payment);
                $captureId = $paypal->verifyCapture($captureResponse, $payment, $invoice);

                if (Payment::query()
                    ->where('paypal_capture_id', $captureId)
                    ->where('id', '!=', $payment->getKey())
                    ->exists()) {
                    throw new PayPalGatewayException('This PayPal capture was already recorded.', 409);
                }

                $payment->update([
                    'paypal_capture_id' => $captureId,
                    'status' => 'completed',
                    'paid_at' => now(),
                ]);
                $balance->synchronizeStatus($invoice);

                return ['payment' => $payment->refresh(), 'invoice' => $invoice->refresh()];
            });
        } catch (PayPalGatewayException $exception) {
            Log::warning('PayPal capture verification failed', [
                'order_id' => $orderId,
                'status' => $exception->httpStatus,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'PayPal order not found.',
            ], 404);
        }

        $invoice = $result['invoice'];
        $payment = $result['payment'];

        return response()->json([
            'message' => 'Payment captured and verified.',
            'payment' => [
                'id' => $payment->id,
                'amount' => Currency::format(
                    Currency::minor((string) $payment->amount, $payment->currency),
                    $payment->currency
                ),
                'currency' => $payment->currency,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'status' => $invoice->status,
                'remaining_balance' => Currency::format(
                    $balance->remainingMinor($invoice),
                    $invoice->currency
                ),
            ],
        ]);
    }

    private function orderResponse(Payment $payment): JsonResponse
    {
        return response()->json([
            'payment_id' => $payment->id,
            'order_id' => $payment->paypal_order_id,
            'approval_url' => $payment->paypal_approval_url,
            'amount' => Currency::format(
                Currency::minor((string) $payment->amount, $payment->currency),
                $payment->currency
            ),
            'currency' => $payment->currency,
            'status' => $payment->status,
        ]);
    }
}
