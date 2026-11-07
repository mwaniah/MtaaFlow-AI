<?php

use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from the dashboard', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('dashboard statistics include only the authenticated user records and completed payments', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Amina']);

    $paidUsdInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DASH-USD',
        'currency' => 'USD',
        'subtotal' => '100.00',
        'total' => '100.00',
        'status' => 'paid',
        'issue_date' => '2026-11-01',
    ]);

    $paidEurInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DASH-EUR',
        'currency' => 'EUR',
        'subtotal' => '80.00',
        'total' => '80.00',
        'status' => 'paid',
        'issue_date' => '2026-11-02',
    ]);

    $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DASH-OUT-USD',
        'currency' => 'USD',
        'subtotal' => '30.00',
        'total' => '30.00',
        'status' => 'approved',
        'issue_date' => '2026-11-03',
    ]);

    $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DASH-OUT-EUR',
        'currency' => 'EUR',
        'subtotal' => '20.00',
        'total' => '20.00',
        'status' => 'approved',
        'issue_date' => '2026-11-04',
    ]);

    $pendingInvoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DASH-PENDING',
        'currency' => 'USD',
        'subtotal' => '70.00',
        'total' => '70.00',
        'status' => 'approved',
        'issue_date' => '2026-11-05',
    ]);

    Payment::query()->create([
        'invoice_id' => $paidUsdInvoice->id,
        'amount' => '100.00',
        'currency' => 'USD',
        'status' => 'completed',
        'paid_at' => '2026-11-05 10:00:00',
    ]);
    Payment::query()->create([
        'invoice_id' => $paidEurInvoice->id,
        'amount' => '80.00',
        'currency' => 'EUR',
        'status' => 'completed',
        'paid_at' => '2026-11-05 11:00:00',
    ]);
    Payment::query()->create([
        'invoice_id' => $pendingInvoice->id,
        'amount' => '70.00',
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $otherUser = User::factory()->create();
    $otherCustomer = $otherUser->customers()->create(['name' => 'Other customer']);
    $otherInvoice = $otherUser->invoices()->create([
        'customer_id' => $otherCustomer->id,
        'invoice_number' => 'INV-DASH-OTHER',
        'currency' => 'USD',
        'subtotal' => '900.00',
        'total' => '900.00',
        'status' => 'paid',
        'issue_date' => '2026-11-05',
    ]);
    Payment::query()->create([
        'invoice_id' => $otherInvoice->id,
        'amount' => '900.00',
        'currency' => 'USD',
        'status' => 'completed',
        'paid_at' => '2026-11-05 12:00:00',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('totalCustomers', 1)
        ->where('totalInvoices', 5)
        ->where('paidInvoiceCount', 2)
        ->where('outstandingInvoiceCount', 3)
        ->where('verifiedEarningsByCurrency.0.currency', 'EUR')
        ->where('verifiedEarningsByCurrency.0.total', 80)
        ->where('verifiedEarningsByCurrency.1.currency', 'USD')
        ->where('verifiedEarningsByCurrency.1.total', 100)
        ->where('outstandingInvoiceTotalsByCurrency.0.currency', 'EUR')
        ->where('outstandingInvoiceTotalsByCurrency.0.total', '20.0000')
        ->where('outstandingInvoiceTotalsByCurrency.1.currency', 'USD')
        ->where('outstandingInvoiceTotalsByCurrency.1.total', '100.0000')
        ->has('recentInvoices', 5)
        ->has('recentPayments', 2));
});
