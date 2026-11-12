<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\InvoiceBalance;
use App\Actions\Invoices\ResolveInvoiceShare;
use App\Actions\Payments\PayPalClient;
use App\Actions\Payments\PayPalGatewayException;
use App\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $payments = Payment::query()
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['invoice.customer'])
            ->latest()
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['payments' => $payments]);
        }

        // Sum each currency using its registered minor-unit precision.
        $totals = $payments
            ->groupBy(fn (Payment $payment): string => $payment->status.'|'.$payment->currency)
            ->map(function ($group): array {
                $first = $group->first();
                $totalMinor = 0;

                foreach ($group as $payment) {
                    $amountMinor = Currency::minor((string) $payment->amount, $first->currency);

                    if ($amountMinor > PHP_INT_MAX - $totalMinor) {
                        throw new \OverflowException('The payment total exceeds the supported range.');
                    }

                    $totalMinor += $amountMinor;
                }

                return [
                    'status' => $first->status,
                    'currency' => $first->currency,
                    'total' => Currency::format($totalMinor, $first->currency),
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
        $invoice = $request->user()->invoices()->findOrFail($invoice->getKey());

        return $this->createOrderForInvoice($request, $invoice, $paypal, $balance);
    }

    public function createSharedPaypalOrder(
        Request $request,
        string $token,
        ResolveInvoiceShare $shares,
        PayPalClient $paypal,
        InvoiceBalance $balance
    ): JsonResponse {
        $share = $shares->handle($token);
        $request->validate([
            'currency' => ['prohibited'],
            'invoice_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ]);

        return $this->createOrderForInvoice($request, $share->invoice, $paypal, $balance, $token);
    }

    public function capturePaypalOrder(
        Request $request,
        string $orderId,
        PayPalClient $paypal,
        InvoiceBalance $balance
    ): JsonResponse {
        $invoice = $request->user()->invoices()
            ->whereHas('payments', fn (Builder $query) => $query->where('paypal_order_id', $orderId))
            ->first();

        if ($invoice === null) {
            return response()->json(['message' => __('PayPal order not found.')], 404);
        }

        return $this->captureOrderForInvoice($invoice, $orderId, $paypal, $balance);
    }

    public function captureSharedPaypalOrder(
        string $token,
        string $orderId,
        ResolveInvoiceShare $shares,
        PayPalClient $paypal,
        InvoiceBalance $balance
    ): JsonResponse {
        $share = $shares->handle($token);

        return $this->captureOrderForInvoice($share->invoice, $orderId, $paypal, $balance);
    }

    private function createOrderForInvoice(
        Request $request,
        Invoice $invoice,
        PayPalClient $paypal,
        InvoiceBalance $balance,
        ?string $shareToken = null,
    ): JsonResponse {
        if (! $paypal->isConfigured()) {
            return response()->json([
                'message' => __('PayPal Sandbox credentials are not configured.'),
            ], 503);
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'amount' => ['nullable', 'string', 'max:32'],
        ]);
        $invoiceId = $invoice->getKey();

        try {
            [$payment, $payerApproved] = DB::transaction(function () use ($invoice, $invoiceId, $validated, $balance, $paypal): array {
                $invoice = Invoice::query()->where('user_id', $invoice->user_id)
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($invoice->status !== 'approved') {
                    throw ValidationException::withMessages([
                        'invoice' => __('Only approved invoices with a balance can be paid.'),
                    ]);
                }

                if (! in_array($invoice->currency, config('services.paypal.supported_currencies', []), true)) {
                    throw ValidationException::withMessages([
                        'currency' => "PayPal does not support {$invoice->currency}. No currency conversion was applied.",
                    ]);
                }

                $keyedPayment = Payment::query()
                    ->where('idempotency_key', $validated['idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($keyedPayment !== null) {
                    abort_unless($keyedPayment->invoice_id === $invoice->id, 404);
                }

                $existingPayment = $keyedPayment !== null && ! in_array($keyedPayment->status, ['failed', 'cancelled'], true)
                    ? $keyedPayment
                    : null;
                $existingPayment ??= $invoice->payments()
                    ->whereIn('status', ['pending', 'processing'])
                    ->lockForUpdate()
                    ->first();

                if ($existingPayment !== null && isset($validated['amount'])) {
                    $requestedAmount = $this->validatedPaymentAmount($invoice, $validated['amount'], $balance);
                    abort_unless(
                        $requestedAmount === Currency::format(Currency::minor((string) $existingPayment->amount, $invoice->currency), $invoice->currency),
                        409,
                        __('A checkout for a different amount is already pending. Clear the amount to resume that checkout.')
                    );
                }

                $retryAmount = null;
                if ($existingPayment !== null && $existingPayment->status === 'pending' && $existingPayment->paypal_order_id !== null) {
                    $existingPayment->setRelation('invoice', $invoice);
                    $order = $paypal->getOrder($existingPayment);

                    if ($order === null || ($order['status'] ?? null) === 'VOIDED') {
                        $retryAmount = Currency::format(
                            min(Currency::minor((string) $existingPayment->amount, $invoice->currency), $balance->remainingMinor($invoice, true)),
                            $invoice->currency
                        );
                        $existingPayment->update(['status' => $order === null ? 'failed' : 'cancelled']);
                        Log::info('PayPal unavailable checkout released', ['payment_id' => $existingPayment->id, 'provider_status' => $order['status'] ?? 'INVALID_RESOURCE_ID']);
                        $existingPayment = null;
                    } elseif (($order['status'] ?? null) === 'COMPLETED') {
                        $this->recordCapture($existingPayment, $invoice, $order, $paypal, $balance);

                        return [$existingPayment->refresh(), false];
                    } elseif (($order['status'] ?? null) === 'APPROVED') {
                        return [$existingPayment, true];
                    } elseif (in_array($order['status'] ?? null, ['CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED'], true)) {
                        $existingPayment->update(['paypal_approval_url' => $paypal->approvalUrl($order)]);
                    } else {
                        throw new PayPalGatewayException(__('PayPal returned an invalid response.'), 502);
                    }
                }

                if ($existingPayment !== null) {
                    return [$existingPayment->load('invoice'), false];
                }

                if ($invoice->payments()->whereIn('status', ['pending', 'processing'])->exists()) {
                    abort(409, __('Another payment checkout is already pending for this invoice.'));
                }

                $amount = $this->validatedPaymentAmount($invoice, $validated['amount'] ?? $retryAmount, $balance);

                $payment = $invoice->payments()->create([
                    'idempotency_key' => $keyedPayment === null ? $validated['idempotency_key'] : (string) Str::uuid(),
                    'paypal_capture_request_id' => (string) Str::uuid(),
                    'amount' => $amount,
                    'currency' => $invoice->currency,
                    'status' => 'pending',
                ])->load('invoice');

                return [$payment, false];
            });
        } catch (PayPalGatewayException $exception) {
            Log::warning('PayPal checkout lookup failed', [
                'invoice_id' => $invoiceId, 'status' => $exception->httpStatus,
                'provider_status' => $exception->providerStatus, 'provider_issue' => $exception->providerIssue,
                'debug_id' => $exception->debugId,
            ]);

            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        }

        if ($payment->paypal_order_id !== null) {
            return $this->orderResponse($payment, $payerApproved);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => __('This checkout attempt has failed. Start a new checkout with a new idempotency key.'),
            ], 409);
        }

        try {
            $payment = DB::transaction(function () use ($payment, $paypal, $shareToken): Payment {
                $lockedPayment = Payment::query()
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedPayment->paypal_order_id !== null) {
                    return $lockedPayment;
                }

                if ($lockedPayment->status !== 'pending') {
                    abort(409, __('This checkout attempt has failed. Start a new checkout with a new idempotency key.'));
                }

                $lockedPayment->load('invoice');
                $paypalOrder = $paypal->createOrder(
                    $lockedPayment,
                    $lockedPayment->invoice,
                    $shareToken === null ? null : route('invoice-shares.public', ['token' => $shareToken, 'checkout' => 'approved']),
                    $shareToken === null ? null : route('invoice-shares.public', ['token' => $shareToken, 'checkout' => 'cancelled']),
                );
                $orderId = $paypalOrder['id'] ?? null;
                $approvalUrl = $paypal->approvalUrl($paypalOrder);

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
                'provider_status' => $exception->providerStatus,
                'provider_issue' => $exception->providerIssue,
                'debug_id' => $exception->debugId,
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

    private function captureOrderForInvoice(
        Invoice $invoice,
        string $orderId,
        PayPalClient $paypal,
        InvoiceBalance $balance
    ): JsonResponse {
        if (! $paypal->isConfigured()) {
            return response()->json([
                'message' => __('PayPal Sandbox credentials are not configured.'),
            ], 503);
        }

        try {
            $result = DB::transaction(function () use ($invoice, $orderId, $paypal, $balance): array {
                $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
                $payment = Payment::query()
                    ->where('paypal_order_id', $orderId)
                    ->where('invoice_id', $invoice->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($payment->status === 'completed') {
                    $invoice = Invoice::query()->whereKey($payment->invoice_id)->firstOrFail();

                    return ['payment' => $payment, 'invoice' => $invoice];
                }

                if ($payment->status !== 'pending') {
                    abort(409, __('This payment cannot be captured in its current state.'));
                }

                if ($invoice->status !== 'approved') {
                    abort(409, __('This invoice is no longer available for payment.'));
                }

                $this->validatedPaymentAmount($invoice, (string) $payment->amount, $balance);
                $payment->setRelation('invoice', $invoice);
                $captureResponse = $paypal->captureOrder($payment);
                $this->recordCapture($payment, $invoice, $captureResponse, $paypal, $balance);

                return ['payment' => $payment->refresh(), 'invoice' => $invoice->refresh()];
            });
        } catch (PayPalGatewayException $exception) {
            Log::warning('PayPal capture verification failed', [
                'order_id' => $orderId,
                'status' => $exception->httpStatus,
                'provider_status' => $exception->providerStatus,
                'provider_issue' => $exception->providerIssue,
                'debug_id' => $exception->debugId,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => __('PayPal order not found.'),
            ], 404);
        }

        $invoice = $result['invoice'];
        $payment = $result['payment'];

        return response()->json([
            'message' => __('Payment captured and verified.'),
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

    /**
     * @param  array<string, mixed>  $captureResponse
     */
    private function recordCapture(Payment $payment, Invoice $invoice, array $captureResponse, PayPalClient $paypal, InvoiceBalance $balance): void
    {
        $captureId = $paypal->verifyCapture($captureResponse, $payment, $invoice);

        if (Payment::query()->where('paypal_capture_id', $captureId)->where('id', '!=', $payment->getKey())->exists()) {
            throw new PayPalGatewayException(__('This PayPal capture was already recorded.'), 409);
        }

        $payment->update(['paypal_capture_id' => $captureId, 'status' => 'completed', 'paid_at' => now()]);
        $balance->synchronizeStatus($invoice);
    }

    private function validatedPaymentAmount(Invoice $invoice, ?string $amount, InvoiceBalance $balance): string
    {
        $remainingMinor = $balance->remainingMinor($invoice, true);

        if ($remainingMinor <= 0) {
            throw ValidationException::withMessages(['invoice' => __('This invoice has no remaining balance.')]);
        }

        $amount ??= Currency::format($remainingMinor, $invoice->currency);

        try {
            $amountMinor = Currency::minor($amount, $invoice->currency);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'amount' => __('Enter an amount using the precision supported by the invoice currency.'),
            ]);
        }

        if ($amountMinor <= 0 || $amountMinor > $remainingMinor) {
            throw ValidationException::withMessages([
                'amount' => __('Payment amount must be greater than zero and no more than the remaining balance.'),
            ]);
        }

        return Currency::format($amountMinor, $invoice->currency);
    }

    private function orderResponse(Payment $payment, bool $payerApproved = false): JsonResponse
    {
        return response()->json([
            'payment_id' => $payment->id,
            'order_id' => $payment->paypal_order_id,
            'approval_url' => $payment->status === 'completed' ? null : $payment->paypal_approval_url,
            'payer_approved' => $payerApproved,
            'amount' => Currency::format(
                Currency::minor((string) $payment->amount, $payment->currency),
                $payment->currency
            ),
            'currency' => $payment->currency,
            'status' => $payment->status,
        ]);
    }
}
