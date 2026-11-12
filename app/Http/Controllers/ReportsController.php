<?php

namespace App\Http\Controllers;

use App\Currency;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Retrieve completed payments belonging to this user
        $verifiedPayments = Payment::query()
            ->where('status', 'completed')
            ->whereHas(
                'invoice',
                fn (Builder $query) => $query
                    ->where('user_id', $user->id)
                    ->whereColumn('invoices.currency', 'payments.currency')
            )
            ->with([
                'invoice:id,user_id,customer_id',
                'invoice.customer:id,name',
            ])
            ->get([
                'id',
                'invoice_id',
                'amount',
                'currency',
                'paid_at',
                'created_at',
            ]);

        // Include only approved invoices with a positive remaining balance.
        $outstandingInvoices = $user->invoices()
            ->where('status', 'approved')
            ->whereRaw(
                'total > COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.invoice_id = invoices.id AND payments.status = ? AND payments.currency = invoices.currency), 0)',
                ['completed']
            );

        // Return financial reports to the Vue page using Inertia
        return Inertia::render('Reports/Index', [
            'verifiedEarningsByCurrency' => $this->summarizeByCurrency($verifiedPayments),

            'monthlyPaymentSummaries' => $verifiedPayments
                ->filter(
                    fn (Payment $payment): bool => $payment->paid_at !== null
                )
                ->groupBy(
                    fn (Payment $payment): string => $payment->paid_at->format('Y-m')
                )
                ->sortKeys()
                ->map(fn (Collection $payments, string $month): array => [
                    'month' => $month,
                    'currencies' => $this->summarizeByCurrency($payments),
                ])
                ->values(),

            'paidInvoiceCount' => $user->invoices()
                ->where('status', 'paid')
                ->count(),

            'unpaidInvoiceCount' => (clone $outstandingInvoices)
                ->count(),

            'outstandingInvoiceTotalsByCurrency' => $outstandingInvoices
                ->select('invoices.currency')
                ->selectRaw(
                    'SUM(invoices.total - COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.invoice_id = invoices.id AND payments.status = ? AND payments.currency = invoices.currency), 0)) as total',
                    ['completed']
                )
                ->groupBy('invoices.currency')
                ->orderBy('invoices.currency')
                ->get()
                ->map(fn (object $summary): array => [
                    'currency' => $summary->currency,
                    'total' => Currency::format(
                        Currency::minor((string) $summary->total, $summary->currency),
                        $summary->currency
                    ),
                ])
                ->values(),

            'customerPaymentSummaries' => $verifiedPayments
                ->groupBy(
                    fn (Payment $payment): int => $payment->invoice->customer_id
                )
                ->flatMap(function (Collection $customerPayments): Collection {
                    $customer = $customerPayments
                        ->first()
                        ->invoice
                        ->customer;

                    return $this->summarizeByCurrency($customerPayments)
                        ->map(fn (array $summary): array => [
                            'customerId' => $customer->id,
                            'customerName' => $customer->name,
                            ...$summary,
                        ]);
                })
                ->values(),
        ]);
    }

    /**
     * Group payments by currency and calculate totals.
     *
     * @param  Collection<int, Payment>  $payments
     * @return Collection<int, array{currency: string, total: string, paymentCount: int}>
     */
    private function summarizeByCurrency(Collection $payments): Collection
    {
        return $payments
            ->groupBy('currency')
            ->sortKeys()
            ->map(fn (Collection $currencyPayments, string $currency): array => [
                'currency' => $currency,
                'total' => $this->sumAmounts($currencyPayments),
                'paymentCount' => $currencyPayments->count(),
            ])
            ->values();
    }

    /**
     * Calculate payment totals using the currency's minor unit.
     *
     * @param  Collection<int, Payment>  $payments
     */
    private function sumAmounts(Collection $payments): string
    {
        $currency = $payments->first()->currency;
        $totalMinor = 0;

        foreach ($payments as $payment) {
            $amountMinor = Currency::minor((string) $payment->amount, $currency);

            if ($amountMinor > PHP_INT_MAX - $totalMinor) {
                throw new \OverflowException('The payment total exceeds the supported range.');
            }

            $totalMinor += $amountMinor;
        }

        return Currency::format($totalMinor, $currency);
    }
}
