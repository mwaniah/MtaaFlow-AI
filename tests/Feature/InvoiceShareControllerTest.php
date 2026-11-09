<?php

use App\Mail\SharedInvoiceMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('invoice owners can create a scoped share link and customers see only invoice details', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Jane Customer',
        'email' => 'jane@example.test',
        'phone' => '+254700000000',
        'address' => 'Private address',
    ]);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SHARE-001',
        'currency' => 'USD',
        'subtotal' => '100',
        'total' => '100',
        'status' => 'approved',
        'issue_date' => '2026-11-07',
    ]);
    $invoice->items()->create([
        'description' => 'Design',
        'quantity' => 1,
        'unit_price' => '100',
        'amount' => '100',
    ]);
    $invoice->payments()->create([
        'amount' => '25',
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    $shareResponse = $this->actingAs($user)
        ->postJson(route('invoices.shares.store', $invoice));

    $shareResponse->assertCreated()
        ->assertJsonPath('email_recipient', 'jane@example.test');

    $shareToken = $shareResponse->json('share_token');
    $publicResponse = $this->getJson(route('invoice-shares.public', ['token' => $shareToken]));

    $publicResponse->assertOk()
        ->assertJsonPath('invoice.invoice_number', 'INV-SHARE-001')
        ->assertJsonPath('invoice.customer_name', 'Jane Customer')
        ->assertJsonPath('invoice.total', '100.00')
        ->assertJsonPath('invoice.payments_received', '25.00')
        ->assertJsonPath('invoice.remaining_balance', '75.00')
        ->assertJsonPath('invoice.status', 'approved')
        ->assertJsonMissing(['email' => 'jane@example.test'])
        ->assertJsonMissing(['address' => 'Private address'])
        ->assertJsonMissing(['phone' => '+254700000000'])
        ->assertJsonMissing(['user_id' => $user->id]);

    expect($shareResponse->json('whatsapp_share_url'))->toContain('wa.me');
});

test('invoice owners can revoke shared links', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Revoked customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SHARE-REVOKE',
        'currency' => 'USD',
        'subtotal' => '20',
        'total' => '20',
        'status' => 'approved',
        'issue_date' => '2026-11-07',
    ]);
    $token = str_repeat('r', 64);
    $share = $invoice->shares()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($user)
        ->deleteJson(route('invoices.shares.destroy', [$invoice, $share]))
        ->assertNoContent();

    $this->getJson(route('invoice-shares.public', ['token' => $token]))
        ->assertNotFound();
});

test('expired invoice share links are unavailable', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Expired customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SHARE-EXPIRED',
        'currency' => 'USD',
        'subtotal' => '20',
        'total' => '20',
        'status' => 'approved',
        'issue_date' => '2026-11-07',
    ]);
    $token = str_repeat('e', 64);
    $invoice->shares()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subMinute(),
    ]);

    $this->getJson(route('invoice-shares.public', ['token' => $token]))
        ->assertNotFound();
});

test('invoice owners can email a share link to the invoice customer', function () {
    Mail::fake();
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Email customer',
        'email' => 'customer@example.test',
    ]);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SHARE-EMAIL',
        'currency' => 'USD',
        'subtotal' => '20',
        'total' => '20',
        'status' => 'approved',
        'issue_date' => '2026-11-07',
    ]);
    $token = str_repeat('m', 64);
    $share = $invoice->shares()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($user)
        ->postJson(route('invoices.shares.email', [$invoice, $share]), [
            'share_token' => $token,
        ])
        ->assertOk()
        ->assertJsonPath('delivery_status', 'sent');

    Mail::assertSent(SharedInvoiceMail::class, fn (SharedInvoiceMail $mail): bool => $mail
        ->hasTo('customer@example.test'));
    $this->assertDatabaseHas('invoice_shares', [
        'id' => $share->id,
        'email_sent_to' => 'customer@example.test',
    ]);
});
