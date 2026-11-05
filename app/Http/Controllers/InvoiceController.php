<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    // List all invoices belonging to the logged-in user
    public function index(Request $request)
    {
        return Inertia::render('Invoices/Index', [
            'invoices' => $request->user()
                ->invoices()
                ->with('customer')
                ->latest()
                ->get(),
        ]);
    }

    // Display the invoice creation form
    public function create(Request $request)
    {
        return Inertia::render('Invoices/Create', [
            'customers' => $request->user()
                ->customers()
                ->orderBy('name')
                ->get(),
        ]);
    }

    // Save a new draft invoice
    public function store(Request $request)
    {
        $validated = $request->validate(
            $this->invoiceRules($request)
        );

        DB::transaction(function () use ($request, $validated) {
            $invoice = $request->user()->invoices()->create([
                'customer_id' => $validated['customer_id'],
                'invoice_number' => 'INV-' . Str::upper(Str::random(16)),
                'currency' => $validated['currency'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
                'subtotal' => 0,
                'total' => 0,
                'status' => 'draft',
            ]);

            $this->saveItems($invoice, $validated['items']);
        });

        return redirect()
            ->route('invoices.index')
            ->with('success', 'Invoice created successfully.');
    }

    // View one invoice and its related records
    public function show(Request $request, $id)
    {
        $invoice = $request->user()
            ->invoices()
            ->with(['customer', 'items', 'payments'])
            ->findOrFail($id);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    // Display the edit form for a draft invoice
    public function edit(Request $request, $id)
    {
        $invoice = $request->user()
            ->invoices()
            ->with('items')
            ->findOrFail($id);

        $this->ensureDraft($invoice);

        return Inertia::render('Invoices/Edit', [
            'invoice' => $invoice,
            'customers' => $request->user()
                ->customers()
                ->orderBy('name')
                ->get(),
        ]);
    }

    // Update a draft invoice
    public function update(Request $request, $id)
    {
        $invoice = $request->user()
            ->invoices()
            ->findOrFail($id);

        $validated = $request->validate(
            $this->invoiceRules($request)
        );

        DB::transaction(function () use ($invoice, $validated) {
            // Lock and recheck the invoice before changing it
            $invoice = $invoice->newQuery()
                ->whereKey($invoice->id)
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

        return redirect()
            ->route('invoices.index')
            ->with('success', 'Invoice updated successfully.');
    }

    // Delete an invoice only if it is still a draft
    public function destroy(Request $request, $id)
    {
        $invoice = $request->user()
            ->invoices()
            ->findOrFail($id);

        DB::transaction(function () use ($invoice) {
            $invoice = $invoice->newQuery()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($invoice);

            if ($invoice->payments()->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => 'An invoice with payment records cannot be deleted.',
                ]);
            }

            $invoice->items()->delete();
            $invoice->reminders()->delete();
            $invoice->delete();
        });

        return redirect()
            ->route('invoices.index')
            ->with('success', 'Invoice deleted successfully.');
    }

    // Approve an invoice after review
    public function approve(Request $request, $id)
    {
        $invoice = $request->user()
            ->invoices()
            ->findOrFail($id);

        DB::transaction(function () use ($invoice) {
            $invoice = $invoice->newQuery()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($invoice);

            if (!$invoice->items()->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => 'An invoice must have at least one item.',
                ]);
            }

            $invoice->update([
                'status' => 'approved',
            ]);
        });

        return redirect()
            ->route('invoices.show', $invoice->id)
            ->with('success', 'Invoice approved successfully.');
    }

    // Validation rules shared by create and update
    private function invoiceRules(Request $request): array
    {
        return [
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')
                    ->where('user_id', $request->user()->id),
            ],
            'currency' => 'required|in:USD,EUR,GBP',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:issue_date',
            'items' => 'required|array|min:1|max:100',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
            'items.*.unit_price' => [
                'required',
                'numeric',
                'min:0',
                'decimal:0,2',
                'max:999999.99',
            ],
        ];
    }

    // Calculate totals and save invoice items
    private function saveItems($invoice, array $items): void
    {
        $totalCents = 0;

        foreach ($items as $item) {
            $price = (string) $item['unit_price'];
            $parts = explode('.', $price, 2);

            $unitCents = ((int) $parts[0] * 100)
                + (int) str_pad($parts[1] ?? '', 2, '0');

            $quantity = (int) $item['quantity'];
            $amountCents = $unitCents * $quantity;

            $totalCents += $amountCents;

            if ($totalCents > 999999999999) {
                throw ValidationException::withMessages([
                    'items' => 'The invoice total is too large.',
                ]);
            }

            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $this->formatMoney($unitCents),
                'amount' => $this->formatMoney($amountCents),
            ]);
        }

        $total = $this->formatMoney($totalCents);

        $invoice->update([
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    // Format integer cents without floating-point calculations
    private function formatMoney(int $cents): string
    {
        return intdiv($cents, 100)
            . '.'
            . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    // Prevent editing, deleting or reapproving non-draft invoices
    private function ensureDraft($invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages([
                'invoice' => 'Only draft invoices can be modified.',
            ]);
        }
    }
}
