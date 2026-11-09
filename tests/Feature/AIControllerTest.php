<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.gemini.key' => null]);
    Http::preventStrayRequests();
});

test('invoice generation returns 503 while AI is not configured', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice for design work.']);

    $response->assertServiceUnavailable()
        ->assertJson(['message' => 'AI integration is not configured yet.']);
});

test('questions return 503 while AI is not configured', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), ['question' => 'How should I follow up?']);

    $response->assertServiceUnavailable()
        ->assertJson(['message' => 'AI integration is not configured yet.']);
});

test('invoice generation returns 422 when a prompt is missing', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.invoice.generate'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('prompt');
});

test('questions return 422 when a question is missing', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('question');
});

test('AI routes return 401 when a freelancer is not authenticated', function (string $route, array $data) {
    $response = $this->postJson(route($route), $data);

    $response->assertUnauthorized();
})->with([
    'invoice generation' => ['ai.invoice.generate', ['prompt' => 'Create an invoice.']],
    'questions' => ['ai.ask', ['question' => 'What is an invoice?']],
]);

test('AI account reads return only low-stock products owned by the authenticated user', function () {
    config(['services.gemini.key' => 'test-key']);
    $user = User::factory()->create();
    $product = $user->products()->create([
        'name' => 'Coffee',
        'low_stock_threshold' => 3,
    ]);
    $product->quantity = 2;
    $product->save();
    User::factory()->create()->products()->create([
        'name' => 'Private tea',
        'low_stock_threshold' => 5,
    ]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'account_read',
        'query' => 'low_stock',
    ])]]]]]])]);

    $response = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Which products are low on stock?',
    ]);

    $response->assertOk()
        ->assertJsonPath('intent', 'account_read')
        ->assertJsonPath('query', 'low_stock')
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.products.0.id', $product->id)
        ->assertJsonPath('data.products.0.quantity', 2)
        ->assertJsonPath('answer', 'Found 1 products at or below their low-stock threshold.');
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
});

test('AI customer creation waits for confirmation and is idempotent', function () {
    config(['services.gemini.key' => 'test-key']);
    $user = User::factory()->create();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'action',
        'action' => 'customer.create',
        'action_data' => [
            'name' => 'New customer',
            'email' => 'new@example.test',
            'phone' => null,
            'address' => null,
        ],
    ])]]]]]])]);

    $draft = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Add New customer with email new@example.test.',
    ]);

    $draft->assertOk()
        ->assertJsonPath('action', 'customer.create')
        ->assertJsonPath('preview.customer.name', 'New customer');
    $this->assertDatabaseCount('customers', 0);

    $token = $draft->json('confirmation.token');
    $confirmation = $this->postJson(route('ai.actions.confirm'), [
        'confirmation_token' => $token,
    ]);

    $confirmation->assertOk()
        ->assertJsonPath('result.customer.name', 'New customer')
        ->assertJsonPath('already_confirmed', false);
    $customerId = $confirmation->json('result.customer.id');

    $this->postJson(route('ai.actions.confirm'), [
        'confirmation_token' => $token,
    ])->assertOk()
        ->assertJsonPath('result.customer.id', $customerId)
        ->assertJsonPath('already_confirmed', true);

    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'user_id' => $user->id,
        'email' => 'new@example.test',
    ]);
    Http::assertSentCount(1);
});

test('AI product creation adds initial stock only after confirmation', function () {
    config(['services.gemini.key' => 'test-key']);
    $user = User::factory()->create();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'action',
        'action' => 'product.create',
        'action_data' => [
            'name' => 'Coffee',
            'sku' => 'AI-COFFEE',
            'quantity' => 9,
            'low_stock_threshold' => 2,
        ],
    ])]]]]]])]);

    $draft = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Add nine units of Coffee to inventory.',
    ]);

    $draft->assertOk()
        ->assertJsonPath('preview.product.quantity', 9);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('inventory_adjustments', 0);

    $confirmation = $this->postJson(route('ai.actions.confirm'), [
        'confirmation_token' => $draft->json('confirmation.token'),
    ]);

    $confirmation->assertOk()
        ->assertJsonPath('result.product.quantity', 9);
    $productId = $confirmation->json('result.product.id');
    $this->assertDatabaseHas('products', [
        'id' => $productId,
        'user_id' => $user->id,
        'quantity' => 9,
    ]);
    $this->assertDatabaseHas('inventory_adjustments', [
        'product_id' => $productId,
        'quantity_before' => 0,
        'quantity_change' => 9,
        'quantity_after' => 9,
    ]);
});

test('AI inventory adjustments are scoped and persist only after confirmation', function () {
    config(['services.gemini.key' => 'test-key']);
    $user = User::factory()->create();
    $product = $user->products()->create(['name' => 'Coffee']);
    $product->quantity = 5;
    $product->save();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'action',
        'action' => 'inventory.adjust',
        'action_data' => [
            'product_name' => 'Coffee',
            'quantity_change' => 3,
            'notes' => 'Restock',
        ],
    ])]]]]]])]);

    $draft = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Add three Coffee units to stock.',
    ]);

    $draft->assertOk()
        ->assertJsonPath('preview.quantity_before', 5)
        ->assertJsonPath('preview.quantity_after', 8);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 5]);
    $this->assertDatabaseCount('inventory_adjustments', 0);

    $confirmation = $this->postJson(route('ai.actions.confirm'), [
        'confirmation_token' => $draft->json('confirmation.token'),
    ]);

    $confirmation->assertOk()
        ->assertJsonPath('result.adjustment.quantity_after', 8);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 8]);
    $this->assertDatabaseCount('inventory_adjustments', 1);
});

test('AI reminder creation revalidates the outstanding balance at confirmation time', function () {
    config(['services.gemini.key' => 'test-key']);
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Reminder customer']);
    $invoice = $user->invoices()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-AI-REMINDER',
        'currency' => 'USD',
        'subtotal' => '50.00',
        'total' => '50.00',
        'status' => 'approved',
        'issue_date' => '2026-11-07',
    ]);
    $invoice->payments()->create([
        'amount' => '10.00',
        'currency' => 'USD',
        'status' => 'completed',
    ]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'action',
        'action' => 'reminder.create',
        'action_data' => [
            'invoice_number' => 'INV-AI-REMINDER',
            'message' => 'Please pay the remaining amount.',
        ],
    ])]]]]]])]);

    $draft = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Prepare a reminder for INV-AI-REMINDER.',
    ]);

    $draft->assertOk()
        ->assertJsonPath('preview.balance_due', '40.00');
    $this->assertDatabaseCount('payment_reminders', 0);

    $invoice->payments()->create([
        'amount' => '40.00',
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    $this->postJson(route('ai.actions.confirm'), [
        'confirmation_token' => $draft->json('confirmation.token'),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_id');

    $this->assertDatabaseCount('payment_reminders', 0);
    $this->assertDatabaseHas('pending_actions', [
        'type' => 'assistant',
        'confirmed_at' => null,
    ]);
});
