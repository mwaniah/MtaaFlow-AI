<?php

use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('reports summarize verified payments and outstanding invoices by currency for the owner', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Report customer']);
    $usdInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REPORT-USD',
        'currency' => 'USD',
        'subtotal' => '50.00',
        'total' => '50.00',
        'status' => 'paid',
        'issue_date' => '2026-11-01',
    ]);
    $eurInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REPORT-EUR',
        'currency' => 'EUR',
        'subtotal' => '25.00',
        'total' => '25.00',
        'status' => 'paid',
        'issue_date' => '2026-11-01',
    ]);
    $unpaidInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REPORT-UNPAID',
        'currency' => 'USD',
        'subtotal' => '35.00',
        'total' => '35.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);

    Payment::query()->create([
        'invoice_id' => $usdInvoice->id,
        'amount' => '15.20',
        'currency' => 'USD',
        'status' => 'completed',
        'paid_at' => '2026-11-05 10:00:00',
    ]);
    Payment::query()->create([
        'invoice_id' => $usdInvoice->id,
        'amount' => '0.30',
        'currency' => 'USD',
        'status' => 'completed',
        'paid_at' => '2026-11-05 11:00:00',
    ]);
    Payment::query()->create([
        'invoice_id' => $eurInvoice->id,
        'amount' => '7.50',
        'currency' => 'EUR',
        'status' => 'completed',
        'paid_at' => '2026-10-15 12:00:00',
    ]);
    Payment::query()->create([
        'invoice_id' => $unpaidInvoice->id,
        'amount' => '35.00',
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $otherUser = User::factory()->create();
    $otherCustomer = $otherUser->customers()->create(['name' => 'Other report customer']);
    $otherInvoice = $otherUser->invoices()->create([
        'customer_id' => $otherCustomer->id,
        'invoice_number' => 'INV-REPORT-OTHER',
        'currency' => 'USD',
        'subtotal' => '999.00',
        'total' => '999.00',
        'status' => 'paid',
        'issue_date' => '2026-11-01',
    ]);
    Payment::query()->create([
        'invoice_id' => $otherInvoice->id,
        'amount' => '999.00',
        'currency' => 'USD',
        'status' => 'completed',
        'paid_at' => '2026-11-05 13:00:00',
    ]);

    $this->withoutVite()
        ->actingAs($user)
        ->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('verifiedEarningsByCurrency.0.currency', 'EUR')
            ->where('verifiedEarningsByCurrency.0.total', '7.50')
            ->where('verifiedEarningsByCurrency.1.currency', 'USD')
            ->where('verifiedEarningsByCurrency.1.total', '15.50')
            ->where('monthlyPaymentSummaries.0.month', '2026-10')
            ->where('monthlyPaymentSummaries.0.currencies.0.total', '7.50')
            ->where('monthlyPaymentSummaries.1.month', '2026-11')
            ->where('monthlyPaymentSummaries.1.currencies.0.total', '15.50')
            ->where('paidInvoiceCount', 2)
            ->where('unpaidInvoiceCount', 1)
            ->where('outstandingInvoiceTotalsByCurrency.0.currency', 'USD')
            ->where('outstandingInvoiceTotalsByCurrency.0.total', '35.00')
            ->where('customerPaymentSummaries.0.customerName', 'Report customer')
            ->where('customerPaymentSummaries.0.currency', 'EUR')
            ->where('customerPaymentSummaries.1.currency', 'USD')
            ->where('customerPaymentSummaries.1.total', '15.50'));
});

test('reports calculate zero and three decimal currencies and retain partial balances', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Precision customer']);

    $jpyInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REPORT-JPY',
        'currency' => 'JPY',
        'subtotal' => '1000',
        'total' => '1000',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $kwdInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REPORT-KWD',
        'currency' => 'KWD',
        'subtotal' => '10',
        'total' => '10',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    Payment::query()->create([
        'invoice_id' => $jpyInvoice->id,
        'amount' => '250',
        'currency' => 'JPY',
        'status' => 'completed',
        'paid_at' => '2026-11-05 10:00:00',
    ]);
    Payment::query()->create([
        'invoice_id' => $kwdInvoice->id,
        'amount' => '1.125',
        'currency' => 'KWD',
        'status' => 'completed',
        'paid_at' => '2026-11-05 11:00:00',
    ]);

    $this->withoutVite()
        ->actingAs($user)
        ->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('verifiedEarningsByCurrency.0.currency', 'JPY')
            ->where('verifiedEarningsByCurrency.0.total', '250')
            ->where('verifiedEarningsByCurrency.1.currency', 'KWD')
            ->where('verifiedEarningsByCurrency.1.total', '1.125')
            ->where('unpaidInvoiceCount', 2)
            ->where('outstandingInvoiceTotalsByCurrency.0.currency', 'JPY')
            ->where('outstandingInvoiceTotalsByCurrency.0.total', '750')
            ->where('outstandingInvoiceTotalsByCurrency.1.currency', 'KWD')
            ->where('outstandingInvoiceTotalsByCurrency.1.total', '8.875'));
});

test('reports are available only to authenticated freelancers', function () {
    $response = $this->getJson(route('reports.index'));

    $response->assertUnauthorized();
});
