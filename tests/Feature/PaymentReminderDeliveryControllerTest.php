<?php

use App\Mail\PaymentReminderMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('email delivery sends the remaining balance and records sent status', function () {
    Mail::fake();
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Reminder customer',
        'email' => 'customer@example.test',
    ]);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-EMAIL',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $invoice->payments()->create([
        'amount' => '15.00',
        'currency' => 'USD',
        'status' => 'completed',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Please settle the balance.',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)->postJson(
        route('payment-reminders.deliveries.store', $reminder),
        ['channel' => 'email'],
    );

    $response->assertOk()
        ->assertJsonPath('delivery_status', 'sent')
        ->assertJsonPath('channel', 'email')
        ->assertJsonPath('balance_due', '45.00')
        ->assertJsonPath('currency', 'USD');
    Mail::assertSent(PaymentReminderMail::class, fn (PaymentReminderMail $mail): bool => $mail
        ->hasTo('customer@example.test')
        && $mail->balanceDue === '45.00'
        && $mail->reminderMessage === 'Please settle the balance.');
    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'status' => 'sent',
        'delivery_channel' => 'email',
        'prepared_at' => null,
    ]);

    $this->postJson(route('payment-reminders.deliveries.store', $reminder), [
        'channel' => 'email',
    ])->assertStatus(409);
    Mail::assertSentCount(1);
});

test('WhatsApp delivery prepares a customer-specific link without claiming it was sent', function () {
    Mail::fake();
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'WhatsApp customer',
        'phone' => '+254 700 000 000',
    ]);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-WHATSAPP',
        'currency' => 'USD',
        'subtotal' => '40.00',
        'total' => '40.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Please review this balance.',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)->postJson(
        route('payment-reminders.deliveries.store', $reminder),
        ['channel' => 'whatsapp'],
    );

    $response->assertOk()
        ->assertJsonPath('delivery_status', 'prepared')
        ->assertJsonPath('channel', 'whatsapp')
        ->assertJsonPath('balance_due', '40.00')
        ->assertJsonPath('currency', 'USD');
    $whatsappUrl = $response->json('whatsapp_url');
    parse_str((string) parse_url($whatsappUrl, PHP_URL_QUERY), $query);
    expect($whatsappUrl)->toContain('wa.me/254700000000');
    expect($query['text'])->toContain('Please review this balance.')
        ->toContain('INV-REMINDER-WHATSAPP')
        ->toContain('40.00 USD');
    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'status' => 'prepared',
        'delivery_channel' => 'whatsapp',
        'sent_at' => null,
    ]);
    Mail::assertNotSent(PaymentReminderMail::class);
});

test('reminder delivery returns 422 when the invoice no longer has a balance', function () {
    Mail::fake();
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Paid customer',
        'email' => 'paid@example.test',
    ]);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-PAID',
        'currency' => 'USD',
        'subtotal' => '60.00',
        'total' => '60.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $invoice->payments()->create([
        'amount' => '60.00',
        'currency' => 'USD',
        'status' => 'completed',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Do not send.',
        'status' => 'draft',
    ]);

    $this->actingAs($user)->postJson(
        route('payment-reminders.deliveries.store', $reminder),
        ['channel' => 'email'],
    )->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_id');

    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'status' => 'draft',
    ]);
    Mail::assertNotSent(PaymentReminderMail::class);
});

test('email delivery returns 422 when the customer has no valid email address', function () {
    Mail::fake();
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'No email customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-NO-EMAIL',
        'currency' => 'USD',
        'subtotal' => '20.00',
        'total' => '20.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Please pay.',
        'status' => 'draft',
    ]);

    $this->actingAs($user)->postJson(
        route('payment-reminders.deliveries.store', $reminder),
        ['channel' => 'email'],
    )->assertUnprocessable()
        ->assertJsonValidationErrors('channel');

    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'status' => 'draft',
    ]);
    Mail::assertNotSent(PaymentReminderMail::class);
});

test('WhatsApp preparation returns 422 for a local number without a country code', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Local phone customer',
        'phone' => '0700000000',
    ]);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-LOCAL-PHONE',
        'currency' => 'USD',
        'subtotal' => '20.00',
        'total' => '20.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Please pay.',
        'status' => 'draft',
    ]);

    $this->actingAs($user)->postJson(
        route('payment-reminders.deliveries.store', $reminder),
        ['channel' => 'whatsapp'],
    )->assertUnprocessable()
        ->assertJsonValidationErrors('channel');

    $this->assertDatabaseHas('payment_reminders', [
        'id' => $reminder->id,
        'status' => 'draft',
    ]);
});

test('reminder delivery returns 404 for another owner', function () {
    $owner = User::factory()->create();
    $customer = $owner->customers()->create([
        'name' => 'Private customer',
        'email' => 'private@example.test',
    ]);
    $invoice = $owner->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-PRIVATE',
        'currency' => 'USD',
        'subtotal' => '20.00',
        'total' => '20.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Private reminder.',
        'status' => 'draft',
    ]);

    $this->actingAs(User::factory()->create())->postJson(
        route('payment-reminders.deliveries.store', $reminder),
        ['channel' => 'email'],
    )->assertNotFound();
});

test('reminder delivery requires authentication', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-REMINDER-AUTH',
        'currency' => 'USD',
        'subtotal' => '20.00',
        'total' => '20.00',
        'status' => 'approved',
        'issue_date' => '2026-11-01',
    ]);
    $reminder = $invoice->reminders()->create([
        'message' => 'Reminder.',
        'status' => 'draft',
    ]);

    $this->postJson(route('payment-reminders.deliveries.store', $reminder), [
        'channel' => 'email',
    ])->assertUnauthorized();
});
