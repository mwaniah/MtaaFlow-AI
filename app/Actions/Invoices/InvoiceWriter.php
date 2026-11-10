<?php

namespace App\Actions\Invoices;

use App\Currency;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function validate(User $user, array $attributes): array
    {
        $currency = $attributes['currency'] ?? null;
        $precision = is_string($currency) && array_key_exists($currency, Currency::all())
            ? Currency::precision($currency)
            : null;

        return Validator::make($attributes, [
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('user_id', $user->id),
            ],
            'submission_id' => ['nullable', 'uuid'],
            'currency' => ['required', Rule::in(array_keys(Currency::all()))],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => [
                'required',
                'numeric',
                'min:0',
                ...($precision === null ? [] : [
                    $precision === 0
                        ? 'regex:/^\d+$/'
                        : 'regex:/^\d+(?:\.\d{1,'.$precision.'})?$/',
                ]),
                'max:999999.99',
            ],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, array $validated): Invoice
    {
        return DB::transaction(function () use ($user, $validated): Invoice {
            $user->newQuery()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! empty($validated['submission_id'])) {
                $existingInvoice = $user->invoices()
                    ->where('submission_id', $validated['submission_id'])
                    ->first();

                if ($existingInvoice !== null) {
                    return $existingInvoice->load(['customer', 'items']);
                }
            }

            $invoice = $user->invoices()->create([
                'submission_id' => $validated['submission_id'] ?? null,
                'customer_id' => $validated['customer_id'],
                'invoice_number' => 'INV-'.Str::upper(Str::random(16)),
                'currency' => $validated['currency'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
                'subtotal' => 0,
                'total' => 0,
                'status' => 'draft',
            ]);

            $this->saveItems($invoice, $validated['items']);

            return $invoice->load(['customer', 'items']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateDraft(Invoice $invoice, array $validated): void
    {
        DB::transaction(function () use ($invoice, $validated): void {
            $invoice = $invoice->newQuery()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($invoice);

            $invoice->update([
                'customer_id' => $validated['customer_id'],
                'currency' => $validated['currency'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
            ]);

            $invoice->items()->delete();

            $this->saveItems($invoice, $validated['items']);
        });
    }

    /**
     * @param  array<int, array{description: string, quantity: int|string, unit_price: int|float|string}>  $items
     */
    private function saveItems(Invoice $invoice, array $items): void
    {
        $totalMinor = 0;
        $maximumInvoiceMinor = 999999999999;

        foreach ($items as $item) {
            $unitMinor = Currency::minor((string) $item['unit_price'], $invoice->currency);
            $quantity = (int) $item['quantity'];

            if ($unitMinor > intdiv($maximumInvoiceMinor - $totalMinor, $quantity)) {
                throw ValidationException::withMessages([
                    'items' => 'The invoice total is too large.',
                ]);
            }

            $amountMinor = $unitMinor * $quantity;
            $totalMinor += $amountMinor;

            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => Currency::format($unitMinor, $invoice->currency),
                'amount' => Currency::format($amountMinor, $invoice->currency),
            ]);
        }

        $total = Currency::format($totalMinor, $invoice->currency);

        $invoice->update([
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    private function ensureDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages([
                'invoice' => 'Only draft invoices can be modified.',
            ]);
        }
    }
}
