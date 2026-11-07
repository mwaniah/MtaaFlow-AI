<?php

namespace App\Http\Controllers;

use App\Models\PaymentReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request): JsonResponse
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

        $invoice = $request->user()->invoices()
            ->whereKey($validated['invoice_id'])
            ->where('status', 'approved')
            ->whereDoesntHave('payments', fn (Builder $query) => $query->where('status', 'completed'))
            ->first();

        if ($invoice === null) {
            throw ValidationException::withMessages([
                'invoice_id' => 'A reminder can only be created for an approved, unpaid invoice.',
            ]);
        }

        $reminder = $invoice->reminders()->create([
            'message' => $validated['message'],
            'status' => 'draft',
        ]);

        return response()->json([
            'reminder' => $reminder->load(['invoice.customer']),
        ], 201);
    }

    public function update(Request $request, PaymentReminder $paymentReminder): JsonResponse
    {
        $paymentReminder = $this->findEditableDraft($request, $paymentReminder);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $paymentReminder->update([
            'message' => $validated['message'],
        ]);

        return response()->json([
            'reminder' => $paymentReminder->load(['invoice.customer']),
        ]);
    }

    public function destroy(Request $request, PaymentReminder $paymentReminder): JsonResponse
    {
        $paymentReminder = $this->findEditableDraft($request, $paymentReminder);
        $paymentReminder->delete();

        return response()->json([], 204);
    }

    private function findEditableDraft(Request $request, PaymentReminder $paymentReminder): PaymentReminder
    {
        return PaymentReminder::query()
            ->whereKey($paymentReminder->getKey())
            ->where('status', 'draft')
            ->whereHas('invoice', function (Builder $query) use ($request): void {
                $query->where('user_id', $request->user()->id)
                    ->where('status', 'approved')
                    ->whereDoesntHave('payments', fn (Builder $paymentQuery) => $paymentQuery->where('status', 'completed'));
            })
            ->firstOrFail();
    }
}
