<?php

namespace App\Http\Controllers;

use App\Currency;
use App\Mail\SharedInvoiceMail;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceShare;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvoiceShareController extends Controller
{
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $invoice = $request->user()
            ->invoices()
            ->with('customer')
            ->findOrFail($invoice->getKey());

        abort_unless(in_array($invoice->status, ['approved', 'paid'], true), 422);
        abort_unless($invoice->customer?->user_id === $request->user()->id, 404);

        $token = Str::random(64);
        $share = $invoice->shares()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => $validated['expires_at'] ?? now()->addDays(30),
        ]);
        $shareUrl = route('invoice-shares.public', ['token' => $token]);
        $message = "Invoice {$invoice->invoice_number} is ready for your review: {$shareUrl}";

        return response()->json([
            'share' => [
                'id' => $share->id,
                'expires_at' => $share->expires_at,
            ],
            'share_token' => $token,
            'share_url' => $shareUrl,
            'whatsapp_share_url' => 'https://wa.me/?text='.rawurlencode($message),
            'email_recipient' => $invoice->customer->email,
            'email_route' => route('invoices.shares.email', [$invoice, $share]),
        ], 201);
    }

    public function destroy(Request $request, Invoice $invoice, InvoiceShare $invoiceShare): JsonResponse
    {
        $invoice = $request->user()->invoices()->findOrFail($invoice->getKey());

        $invoice->shares()
            ->whereKey($invoiceShare->getKey())
            ->firstOrFail()
            ->update(['revoked_at' => now()]);

        return response()->json([], 204);
    }

    public function email(Request $request, Invoice $invoice, InvoiceShare $invoiceShare): JsonResponse
    {
        $validated = $request->validate([
            'share_token' => ['required', 'string', 'size:64'],
        ]);
        $invoice = $request->user()
            ->invoices()
            ->with('customer')
            ->findOrFail($invoice->getKey());
        $share = $invoice->shares()
            ->whereKey($invoiceShare->getKey())
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->firstOrFail();

        abort_unless($invoice->customer?->user_id === $request->user()->id, 404);

        if (! is_string($invoice->customer->email) || $invoice->customer->email === '') {
            return response()->json([
                'message' => 'The customer does not have an email address on file.',
            ], 422);
        }

        if (! hash_equals($share->token_hash, hash('sha256', $validated['share_token']))) {
            abort(404);
        }

        $shareUrl = route('invoice-shares.public', ['token' => $validated['share_token']]);

        Mail::to($invoice->customer->email)->send(new SharedInvoiceMail(
            $invoice->invoice_number,
            $invoice->customer->name,
            $shareUrl
        ));

        $share->update([
            'emailed_at' => now(),
            'email_sent_to' => $invoice->customer->email,
        ]);

        return response()->json([
            'message' => 'Invoice link email sent.',
            'delivery_status' => 'sent',
            'emailed_at' => $share->emailed_at,
        ]);
    }

    public function show(string $token): JsonResponse
    {
        $share = InvoiceShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->with([
                'invoice.customer',
                'invoice.items',
                'invoice.payments' => fn (HasMany $query) => $query->where('status', 'completed'),
            ])
            ->firstOrFail();
        $invoice = $share->invoice;

        abort_unless($invoice->customer?->user_id === $invoice->user_id, 404);

        $totalMinor = Currency::minor((string) $invoice->total, $invoice->currency);
        $paidMinor = 0;

        foreach ($invoice->payments as $payment) {
            if ($payment->currency !== $invoice->currency) {
                continue;
            }

            $amountMinor = Currency::minor((string) $payment->amount, $invoice->currency);

            if ($amountMinor > PHP_INT_MAX - $paidMinor) {
                throw new \OverflowException('The payment total exceeds the supported range.');
            }

            $paidMinor += $amountMinor;
        }

        $balanceMinor = max(0, $totalMinor - $paidMinor);

        return response()->json([
            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer->name,
                'issue_date' => $invoice->issue_date,
                'due_date' => $invoice->due_date,
                'currency' => $invoice->currency,
                'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => Currency::format(
                        Currency::minor((string) $item->unit_price, $invoice->currency),
                        $invoice->currency
                    ),
                    'amount' => Currency::format(
                        Currency::minor((string) $item->amount, $invoice->currency),
                        $invoice->currency
                    ),
                ])->values(),
                'subtotal' => Currency::format(
                    Currency::minor((string) $invoice->subtotal, $invoice->currency),
                    $invoice->currency
                ),
                'total' => Currency::format($totalMinor, $invoice->currency),
                'payments_received' => Currency::format($paidMinor, $invoice->currency),
                'remaining_balance' => Currency::format($balanceMinor, $invoice->currency),
                'status' => $balanceMinor === 0 ? 'paid' : 'approved',
            ],
        ]);
    }
}
