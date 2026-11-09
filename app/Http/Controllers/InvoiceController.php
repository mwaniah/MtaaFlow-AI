<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\InvoiceWriter;
use App\Currency;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'currencies' => Currency::all(),
            'customers' => $request->user()
                ->customers()
                ->orderBy('name')
                ->get(),
        ]);
    }

    // Save a new draft invoice
    public function store(Request $request, InvoiceWriter $writer)
    {
        $validated = $writer->validate($request->user(), $request->all());
        $writer->create($request->user(), $validated);

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
            'currencies' => Currency::all(),
            'invoice' => $invoice,
            'customers' => $request->user()
                ->customers()
                ->orderBy('name')
                ->get(),
        ]);
    }

    // Update a draft invoice
    public function update(Request $request, $id, InvoiceWriter $writer)
    {
        $invoice = $request->user()
            ->invoices()
            ->findOrFail($id);

        $validated = $writer->validate($request->user(), $request->all());
        $writer->updateDraft($invoice, $validated);

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

            if (! $invoice->items()->exists()) {
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
