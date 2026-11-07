<?php

use App\Models\Payment;
use App\Models\User;

test('a freelancer can create a reminder draft for an approved unpaid invoice', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Reminder customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-STORE',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);

    $response = $this->actingAs($user)->postJson(route('payment-reminders.store'), [
        'invoice_id' => $invoice->id,
        'message' => 'A friendly payment reminder.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('reminder.status', 'draft')
        ->assertJsonPath('reminder.invoice.customer.name', 'Reminder customer');
    $this->assertDatabaseHas('payment_reminders', [
        'invoice_id' => $invoice->id,
        'message' => 'A friendly payment reminder.',
        'status' => 'draft',
    ]);
});

test('reminder creation returns 422 for another freelancers invoice', function () {
    $owner = User::factory()->create();
    $customer = $owner->customers()->create(['name' => 'Private customer']);
    $invoice = $owner->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-PRIVATE',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('payment-reminders.store'), [
            'invoice_id' => $invoice->id,
            'message' => 'A reminder.',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_id');
    $this->assertDatabaseMissing('payment_reminders', ['invoice_id' => $invoice->id]);
});

test('reminder creation returns 422 for a draft invoice', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Draft customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-DRAFT',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'draft',
        'issue_date' => '2026-11-01',
    ]);

    $response = $this->actingAs($user)->postJson(route('payment-reminders.store'), [
        'invoice_id' => $invoice->id,
        'message' => 'A reminder.',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_id');
});

test('reminder creation returns 422 for an invoice with a completed payment', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Paid customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-PAID',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'amount' => '60.00',
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    $response = $this->actingAs($user)->postJson(route('payment-reminders.store'), [
        'invoice_id' => $invoice->id,
        'message' => 'A reminder.',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_id');
});

test('reminder creation returns 422 when the message is missing', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Reminder customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-NO-MESSAGE',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);

    $response = $this->actingAs($user)->postJson(route('payment-reminders.store'), [
        'invoice_id' => $invoice->id,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});

test('a freelancer can update their reminder draft', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Reminder customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-EDIT',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Original message.',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)->putJson(route('payment-reminders.update', $reminder), [
        'message' => 'Updated message.',
    ]);

    $response->assertOk()
        ->assertJsonPath('reminder.message', 'Updated message.');
    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'message' => 'Updated message.',
        'status' => 'draft',
    ]);
});

test('reminder updates return 422 when the message is missing', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Reminder customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-INVALID-UPDATE',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Keep original message.',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)->putJson(route('payment-reminders.update', $reminder), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('message');
    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'message' => 'Keep original message.',
    ]);
});

test('a freelancer can delete their reminder draft', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Reminder customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-DELETE',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Draft to delete.',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)->deleteJson(route('payment-reminders.destroy', $reminder));

    $response->assertNoContent();
    $this->assertDatabaseMissing('payment_reminders', ['id' => $reminder->id]);
});

test('reminder updates return 404 for another users reminder', function () {
    $owner = User::factory()->create();
    $customer = $owner->customers()->create(['name' => 'Private customer']);
    $invoice = $owner->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-OTHER',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Private draft.',
        'status' => 'draft',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->putJson(route('payment-reminders.update', $reminder), [
            'message' => 'Changed by another user.',
        ]);

    $response->assertNotFound();
    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'message' => 'Private draft.',
    ]);
});

test('reminder listing includes only the authenticated users records', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Own reminder customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-LIST-OWN',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Own reminder.',
        'status' => 'draft',
    ]);

    $otherUser = User::factory()->create();
    $otherCustomer = $otherUser->customers()->create(['name' => 'Other reminder customer']);
    $otherInvoice = $otherUser->invoices()->create([
        'customer_id' => $otherCustomer->id,
        'invoice_number' => 'INV-REMINDER-LIST-OTHER',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $otherInvoice->reminders()->create([
        'message' => 'Other reminder.',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)->getJson(route('payment-reminders.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'reminders')
        ->assertJsonPath('reminders.0.id', $reminder->id)
        ->assertJsonPath('reminders.0.invoice.customer.name', 'Own reminder customer');
});

test('reminder listing requires authentication', function () {
    $response = $this->getJson(route('payment-reminders.index'));

    $response->assertUnauthorized();
});
