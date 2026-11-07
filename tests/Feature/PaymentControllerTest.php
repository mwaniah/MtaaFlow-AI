<?php

use App\Models\Payment;
use App\Models\User;

test('payment listing includes only payments belonging to the authenticated user', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Customer One']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-PAYMENT-OWN',
        'currency' => 'USD',
        'subtotal' => '40.00',
        'total' => '40.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'amount' => '40.00',
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $otherUser = User::factory()->create();
    $otherCustomer = $otherUser->customers()->create(['name' => 'Customer Two']);
    $otherInvoice = $otherUser->invoices()->create([
        'customer_id' => $otherCustomer->id,
        'invoice_number' => 'INV-PAYMENT-OTHER',
        'currency' => 'EUR',
        'subtotal' => '50.00',
        'total' => '50.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    Payment::query()->create([
        'invoice_id' => $otherInvoice->id,
        'amount' => '50.00',
        'currency' => 'EUR',
        'status' => 'completed',
    ]);

    $response = $this->actingAs($user)->getJson(route('payments.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.id', $payment->id)
        ->assertJsonPath('payments.0.invoice.customer.name', 'Customer One');
});

test('payment listing requires authentication', function () {
    $response = $this->getJson(route('payments.index'));

    $response->assertUnauthorized();
});

test('payment details return 404 for another freelancers payment', function () {
    $owner = User::factory()->create();
    $customer = $owner->customers()->create(['name' => 'Private customer']);
    $invoice = $owner->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-PAYMENT-PRIVATE',
        'currency' => 'USD',
        'subtotal' => '40.00',
        'total' => '40.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'amount' => '40.00',
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('payments.show', $payment));

    $response->assertNotFound();
});

test('payment details include the invoice and customer for the owner', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Own customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-PAYMENT-SHOW',
        'currency' => 'USD',
        'subtotal' => '40.00',
        'total' => '40.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'amount' => '40.00',
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($user)->getJson(route('payments.show', $payment));

    $response->assertOk()
        ->assertJsonPath('payment.id', $payment->id)
        ->assertJsonPath('payment.invoice.customer.name', 'Own customer');
});

test('payment routes do not accept payment creation requests', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-NO-PAYMENT-CREATE',
        'currency' => 'USD',
        'subtotal' => '40.00',
        'total' => '40.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);

    $response = $this->actingAs($user)->postJson(route('payments.index'), [
        'invoice_id' => $invoice->id,
        'status' => 'completed',
    ]);

    $response->assertMethodNotAllowed();
    $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
});
