<?php

namespace App\Actions\Invoices;

use App\Currency;
use App\Models\Invoice;
use OverflowException;

class InvoiceBalance
{
    public function paidMinor(Invoice $invoice, bool $lockForUpdate = false): int
    {
        $payments = $invoice->payments()
            ->where('status', 'completed')
            ->where('currency', $invoice->currency)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get(['amount']);
        $paidMinor = 0;

        foreach ($payments as $payment) {
            $amountMinor = Currency::minor((string) $payment->amount, $invoice->currency);

            if ($amountMinor > PHP_INT_MAX - $paidMinor) {
                throw new OverflowException('The payment total exceeds the supported range.');
            }

            $paidMinor += $amountMinor;
        }

        return $paidMinor;
    }

    public function totalMinor(Invoice $invoice): int
    {
        return Currency::minor((string) $invoice->total, $invoice->currency);
    }

    public function remainingMinor(Invoice $invoice, bool $lockPayments = false): int
    {
        return max(0, $this->totalMinor($invoice) - $this->paidMinor($invoice, $lockPayments));
    }

    public function synchronizeStatus(Invoice $invoice): void
    {
        if (! in_array($invoice->status, ['approved', 'paid'], true)) {
            return;
        }

        $status = $this->remainingMinor($invoice, true) === 0 ? 'paid' : 'approved';

        if ($invoice->status !== $status) {
            $invoice->update(['status' => $status]);
        }
    }
}
