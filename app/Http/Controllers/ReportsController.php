<?php

namespace App\Http\Controllers;

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
                fn (Builder $query) => $query->where('user_id', $user->id)
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

        // Retrieve approved invoices without completed payments
        $outstandingInvoices = $user->invoices()
            ->where('status', 'approved')
            ->whereDoesntHave(
                'payments',
                fn (Builder $query) => $query->where('status', 'completed')
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
                ->select('currency')
                ->selectRaw('SUM(total) as total')
                ->groupBy('currency')
                ->orderBy('currency')
                ->get(),

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
     * Calculate payment totals using integer cents.
     *
     * @param  Collection<int, Payment>  $payments
     */
    private function sumAmounts(Collection $payments): string
    {
        $totalCents = 0;

        foreach ($payments as $payment) {
            [$whole, $fraction] = array_pad(
                explode('.', (string) $payment->amount, 2),
                2,
                ''
            );

            $totalCents += ((int) $whole * 100)
                + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }

        return intdiv($totalCents, 100)
            .'.'
            .str_pad(
                (string) ($totalCents % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }
}
