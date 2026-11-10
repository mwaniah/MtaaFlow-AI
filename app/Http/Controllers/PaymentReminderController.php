<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\InvoiceBalance;
use App\Models\PaymentReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reminders = PaymentReminder::query()
            ->whereHas('invoice', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['invoice.customer'])
            ->latest()
            ->get();

        return response()->json([
            'reminders' => $reminders,
        ]);
    }

    public function store(Request $request, InvoiceBalance $balance): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')
                    ->where('user_id', $request->user()->id)
                    ->where('status', 'approved'),
            ],
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $reminder = DB::transaction(function () use ($request, $validated, $balance): PaymentReminder {
            $invoice = $request->user()->invoices()
                ->with('customer')
                ->whereKey($validated['invoice_id'])
                ->where('status', 'approved')
                ->lockForUpdate()
                ->first();

            if (
                $invoice === null
                || $invoice->customer?->user_id !== $request->user()->id
                || $balance->remainingMinor($invoice) <= 0
            ) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'A reminder can only be created for an approved invoice with an outstanding balance.',
                ]);
            }

            return $invoice->reminders()->create([
                'message' => $validated['message'],
                'status' => 'draft',
            ]);
        });

        return response()->json([
            'reminder' => $reminder->load(['invoice.customer']),
        ], 201);
    }

    public function update(
        Request $request,
        PaymentReminder $paymentReminder,
        InvoiceBalance $balance,
    ): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $paymentReminder = DB::transaction(function () use ($request, $paymentReminder, $balance, $validated): PaymentReminder {
            $paymentReminder = $this->findEditableDraft($request, $paymentReminder, $balance);
            $paymentReminder->update(['message' => $validated['message']]);

            return $paymentReminder;
        });

        return response()->json([
            'reminder' => $paymentReminder->load(['invoice.customer']),
        ]);
    }

    public function destroy(
        Request $request,
        PaymentReminder $paymentReminder,
        InvoiceBalance $balance,
    ): JsonResponse
    {
        DB::transaction(function () use ($request, $paymentReminder, $balance): void {
            $this->findEditableDraft($request, $paymentReminder, $balance)->delete();
        });

        return response()->json([], 204);
    }

    private function findEditableDraft(
        Request $request,
        PaymentReminder $paymentReminder,
        InvoiceBalance $balance,
    ): PaymentReminder
    {
        $reminder = PaymentReminder::query()
            ->whereKey($paymentReminder->getKey())
            ->where('status', 'draft')
            ->whereHas('invoice', function (Builder $query) use ($request): void {
                $query->where('user_id', $request->user()->id)
                    ->where('status', 'approved');
            })
            ->lockForUpdate()
            ->firstOrFail();

        $invoice = $request->user()->invoices()
            ->whereKey($reminder->invoice_id)
            ->where('status', 'approved')
            ->lockForUpdate()
            ->firstOrFail();

        if ($balance->remainingMinor($invoice) <= 0) {
            abort(404);
        }

        return $reminder;
    }
}
