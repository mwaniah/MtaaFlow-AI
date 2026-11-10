<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $verifiedPayments = Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->whereColumn('invoices.currency', 'payments.currency'));

        $outstandingInvoices = $user->invoices()
            ->where('status', 'approved')
            ->whereRaw('total > COALESCE((SELECT SUM(amount) FROM payments WHERE payments.invoice_id = invoices.id AND payments.status = ? AND payments.currency = invoices.currency), 0)', ['completed']);

        return Inertia::render('Dashboard', [
            'totalCustomers' => $user->customers()->count(),
            'totalInvoices' => $user->invoices()->count(),
            'paidInvoiceCount' => $user->invoices()->where('status', 'paid')->count(),
            'outstandingInvoiceCount' => (clone $outstandingInvoices)->count(),
            'recentInvoices' => $user->invoices()
                ->with('customer')
                ->latest()
                ->limit(5)
                ->get(),
            'recentPayments' => (clone $verifiedPayments)
                ->with(['invoice.customer'])
                ->latest()
                ->limit(5)
                ->get(),
            'verifiedEarningsByCurrency' => (clone $verifiedPayments)
                ->select('currency')
                ->selectRaw('SUM(amount) as total')
                ->groupBy('currency')
                ->orderBy('currency')
                ->get(),
            'outstandingInvoiceTotalsByCurrency' => $outstandingInvoices
                ->select('currency')
                ->selectRaw('SUM(total - COALESCE((SELECT SUM(amount) FROM payments WHERE payments.invoice_id = invoices.id AND payments.status = ? AND payments.currency = invoices.currency), 0)) as total', ['completed'])
                ->groupBy('currency')
                ->orderBy('currency')
                ->get(),
        ]);
    }
}
