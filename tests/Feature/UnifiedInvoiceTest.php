<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('confirmed invoices preserve currency precision and duplicate requests create one draft', function (string $currency, string $price, string $total) {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Mary']);
    $data = [
        'customer_id' => $customer->id,
        'currency' => $currency,
        'issue_date' => '2026-11-07',
        'submission_id' => 'f58440d0-1130-4f51-99c3-22b3fc539955',
        'items' => [['description' => 'Design', 'quantity' => 3, 'unit_price' => $price]],
    ];

    $this->actingAs($user)->post(route('invoices.store'), $data)->assertRedirect(route('invoices.index'));
    $this->post(route('invoices.store'), $data)->assertRedirect(route('invoices.index'));

    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseHas('invoices', ['currency' => $currency, 'total' => $total, 'status' => 'draft']);
})->with([
    'zero decimals' => ['JPY', '123', '369'],
    'three decimals' => ['KWD', '1.125', '3.375'],
    'Kenyan shillings' => ['KES', '20.10', '60.30'],
]);

test('invalid currencies and excess precision cannot create invoices', function (string $currency, string $price, string $field) {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Mary']);

    $this->actingAs($user)->postJson(route('invoices.store'), [
        'customer_id' => $customer->id, 'currency' => $currency, 'issue_date' => '2026-11-07',
        'items' => [['description' => 'Design', 'quantity' => 1, 'unit_price' => $price]],
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('invoices', 0);
})->with([
    ['ZZZ', '10', 'currency'],
    ['JPY', '10.50', 'items.0.unit_price'],
    ['USD', '1e3', 'items.0.unit_price'],
]);

test('AI invoice previews require explicit confirmation and save only once', function () {
    config(['services.gemini.key' => 'test-key']);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Mary']);
    User::factory()->create()->customers()->create(['name' => 'Mary']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'invoice', 'message' => 'Review this draft.', 'customer_name' => 'Mary', 'currency' => 'JPY',
        'issue_date' => '2026-11-07', 'due_date' => null,
        'items' => [['description' => 'Design', 'quantity' => 1, 'unit_price' => '80000']],
    ])]]]]]])]);

    $draftResponse = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Invoice Mary JPY 80000 for Design',
    ]);

    $draftResponse->assertOk()
        ->assertJsonPath('intent', 'invoice')
        ->assertJsonPath('draft.customer_id', $customer->id)
        ->assertJsonCount(1, 'customer_matches');

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('pending_actions', 1);

    $confirmationToken = $draftResponse->json('confirmation.token');
    $confirmation = $this->postJson(route('ai.invoice.confirm'), [
        'confirmation_token' => $confirmationToken,
    ]);

    $confirmation->assertOk()
        ->assertJsonPath('invoice.currency', 'JPY')
        ->assertJsonPath('invoice.total', '80000')
        ->assertJsonPath('invoice.status', 'draft')
        ->assertJsonPath('printer_animation.status', 'ready');

    $invoiceId = $confirmation->json('invoice_id');
    $this->postJson(route('ai.invoice.confirm'), [
        'confirmation_token' => $confirmationToken,
    ])->assertOk()->assertJsonPath('invoice_id', $invoiceId);

    $this->assertDatabaseCount('invoices', 1);
    Http::assertSentCount(1);
});

test('invalid AI output returns an error without creating an invoice', function (string $text, int $status) {
    config(['services.gemini.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Create an invoice'])
        ->assertStatus($status);

    $this->assertDatabaseCount('invoices', 0);
    Http::assertSentCount(1);
})->with([['not json', 502], ['{}', 422]]);

test('an invoice cannot use another freelancers customer', function () {
    $otherCustomer = User::factory()->create()->customers()->create(['name' => 'Other']);

    $this->actingAs(User::factory()->create())->postJson(route('invoices.store'), [
        'customer_id' => $otherCustomer->id, 'currency' => 'USD', 'issue_date' => '2026-11-07',
        'items' => [['description' => 'Design', 'quantity' => 1, 'unit_price' => '20']],
    ])->assertUnprocessable()->assertJsonValidationErrors('customer_id');

    $this->assertDatabaseCount('invoices', 0);
});

test('partially paid invoices remain outstanding with their remaining balance', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Mary']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id, 'invoice_number' => 'PARTIAL-1', 'currency' => 'USD',
        'issue_date' => '2026-11-07', 'subtotal' => '100', 'total' => '100', 'status' => 'approved',
    ]);
    $invoice->payments()->create(['amount' => '25', 'currency' => 'USD', 'status' => 'completed']);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('outstandingInvoiceCount', 1)
        ->where('outstandingInvoiceTotalsByCurrency.0.total', '75.0000')
        ->where('verifiedEarningsByCurrency.0.total', 25));

    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'approved']);
});

test('ambiguous or missing customers require selection and incomplete drafts are never saved', function (int $customers) {
    config(['services.gemini.key' => 'test-key']);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    for ($index = 0; $index < $customers; $index++) {
        $user->customers()->create(['name' => 'Mary']);
    }
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'invoice', 'message' => 'Please supply the missing details.', 'customer_name' => 'Mary',
        'currency' => null, 'issue_date' => null, 'due_date' => null, 'items' => [],
    ])]]]]]])]);

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Invoice Mary'])
        ->assertOk()->assertJsonPath('draft.customer_id', null)->assertJsonPath('draft.due_date', null)
        ->assertJsonPath('draft.currency', null)->assertJsonCount($customers, 'customer_matches');

    $this->assertDatabaseCount('invoices', 0);
    Http::assertSentCount(1);
})->with(['unknown' => 0, 'ambiguous' => 2]);

test('invoice confirmation tokens cannot be used by another user', function () {
    $owner = User::factory()->create();
    $customer = $owner->customers()->create(['name' => 'Private customer']);
    $token = Str::random(64);
    $owner->pendingActions()->create([
        'token_hash' => hash('sha256', $token),
        'type' => 'invoice',
        'payload' => [
            'customer_id' => $customer->id,
            'currency' => 'USD',
            'issue_date' => '2026-11-07',
            'items' => [['description' => 'Design', 'quantity' => 1, 'unit_price' => '25']],
        ],
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.invoice.confirm'), ['confirmation_token' => $token])
        ->assertNotFound();

    $this->assertDatabaseCount('invoices', 0);
});

test('expired invoice confirmation tokens do not create invoices', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Expired customer']);
    $token = Str::random(64);
    $user->pendingActions()->create([
        'token_hash' => hash('sha256', $token),
        'type' => 'invoice',
        'payload' => [
            'customer_id' => $customer->id,
            'currency' => 'USD',
            'issue_date' => '2026-11-07',
            'items' => [['description' => 'Design', 'quantity' => 1, 'unit_price' => '25']],
        ],
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->postJson(route('ai.invoice.confirm'), ['confirmation_token' => $token])
        ->assertStatus(410);

    $this->assertDatabaseCount('invoices', 0);
});
