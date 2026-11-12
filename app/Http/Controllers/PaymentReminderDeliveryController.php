<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\InvoiceBalance;
use App\Currency;
use App\Mail\PaymentReminderMail;
use App\Models\PaymentReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentReminderDeliveryController extends Controller
{
    public function store(
        Request $request,
        PaymentReminder $paymentReminder,
        InvoiceBalance $balance,
    ): JsonResponse {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(['email', 'whatsapp'])],
        ]);

        return DB::transaction(function () use ($request, $paymentReminder, $balance, $validated): JsonResponse {
            $reminder = PaymentReminder::query()
                ->whereKey($paymentReminder->getKey())
                ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $request->user()->id))
                ->lockForUpdate()
                ->firstOrFail();

            if ($reminder->status !== 'draft') {
                abort(409, __('Only draft reminders can be delivered.'));
            }

            $invoice = $request->user()->invoices()
                ->with('customer')
                ->whereKey($reminder->invoice_id)
                ->where('status', 'approved')
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $invoice->customer === null
                || $invoice->customer->user_id !== $request->user()->id
            ) {
                abort(404);
            }

            $remainingMinor = $balance->remainingMinor($invoice);

            if ($remainingMinor <= 0) {
                throw ValidationException::withMessages([
                    'invoice_id' => __('A reminder can only be delivered while the invoice has an outstanding balance.'),
                ]);
            }

            $formattedBalance = Currency::format($remainingMinor, $invoice->currency);

            if ($validated['channel'] === 'email') {
                $email = $invoice->customer->email;

                if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw ValidationException::withMessages([
                        'channel' => __('The customer does not have a valid email address on file.'),
                    ]);
                }

                Mail::to($email)->send(new PaymentReminderMail(
                    $invoice->customer->name,
                    $invoice->invoice_number,
                    $reminder->message,
                    $formattedBalance,
                    $invoice->currency,
                ));

                $reminder->update([
                    'status' => 'sent',
                    'delivery_channel' => 'email',
                    'sent_at' => now(),
                    'prepared_at' => null,
                ]);

                return response()->json([
                    'delivery_status' => 'sent',
                    'channel' => 'email',
                    'sent_at' => $reminder->sent_at,
                    'balance_due' => $formattedBalance,
                    'currency' => $invoice->currency,
                ]);
            }

            $phoneNumber = preg_replace('/\D+/', '', $invoice->customer->phone ?? '') ?? '';

            if (preg_match('/\A[1-9]\d{7,14}\z/', $phoneNumber) !== 1) {
                throw ValidationException::withMessages([
                    'channel' => __('The customer phone number must include an international country code for WhatsApp.'),
                ]);
            }

            $message = trim($reminder->message)
                ."\n\nOutstanding balance for invoice {$invoice->invoice_number}: {$formattedBalance} {$invoice->currency}.";
            $whatsappUrl = 'https://wa.me/'.$phoneNumber.'?text='.rawurlencode($message);

            $reminder->update([
                'status' => 'prepared',
                'delivery_channel' => 'whatsapp',
                'sent_at' => null,
                'prepared_at' => now(),
            ]);

            return response()->json([
                'delivery_status' => 'prepared',
                'channel' => 'whatsapp',
                'prepared_at' => $reminder->prepared_at,
                'whatsapp_url' => $whatsappUrl,
                'balance_due' => $formattedBalance,
                'currency' => $invoice->currency,
            ]);
        });
    }
}
