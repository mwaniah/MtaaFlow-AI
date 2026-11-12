<?php

use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['services.gemini.key' => null, 'services.ai.primary_provider' => 'gemini', 'services.ai.fallback_provider' => null]);
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
        ->assertJsonPath('answer', fn (string $answer): bool => str_starts_with($answer, 'You have 1 product at or below its low-stock threshold.') && str_contains($answer, 'Coffee'));
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
            'phone' => '+254700000001',
            'address' => null,
        ],
    ])]]]]]])]);

    $draft = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Add New customer with email new@example.test and phone +254700000001.',
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
        'phone' => '+254700000001',
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

test('an empty account summary contains only the new users recorded counts', function () {
    config(['services.gemini.key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'account_read', 'query' => 'summary', 'message' => 'Revenue grew by 90 percent.',
    ])]]]]]])]);
    $user = User::factory()->create();
    User::factory()->create()->customers()->create(['name' => 'Private customer']);

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'How is my business doing?'])
        ->assertOk()
        ->assertJsonPath('answer', fn (string $answer): bool => str_starts_with($answer, 'Your business summary:') && ! str_contains($answer, '90 percent'))
        ->assertJsonPath('data', [
            'query' => 'summary', 'customers' => 0, 'total_invoices' => 0, 'paid_invoices' => 0,
            'outstanding_invoices' => 0, 'totals_by_currency' => [], 'products' => 0,
            'low_stock_products' => 0, 'approved_invoices' => 0, 'reminders' => 0,
        ]);
    Http::assertSentCount(1);
});

test('earnings contain only completed payments from the requesting account', function () {
    config(['services.gemini.key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'account_read', 'query' => 'revenue',
    ])]]]]]])]);
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    foreach ([$owner, $otherUser] as $index => $user) {
        $customer = $user->customers()->create(['name' => 'Customer']);
        $invoice = $user->invoices()->create([
            'customer_id' => $customer->id, 'invoice_number' => 'AI-REVENUE-'.$index,
            'currency' => 'USD', 'issue_date' => '2026-11-10', 'subtotal' => '100', 'total' => '100', 'status' => 'approved',
        ]);
        $invoice->payments()->create(['currency' => 'USD', 'amount' => $index === 0 ? '25' : '75', 'status' => 'completed']);
        $invoice->payments()->create(['currency' => 'USD', 'amount' => '10', 'status' => 'pending']);
        $invoice->payments()->create(['currency' => 'EUR', 'amount' => '999', 'status' => 'completed']);
    }

    $this->actingAs($owner)->postJson(route('ai.ask'), ['question' => 'Show my earnings'])
        ->assertOk()->assertJsonPath('data.totals_by_currency', [['currency' => 'USD', 'total' => '25.00']]);
    $this->actingAs($otherUser)->postJson(route('ai.ask'), ['question' => 'Show my earnings'])
        ->assertOk()->assertJsonPath('data.totals_by_currency', [['currency' => 'USD', 'total' => '75.00']]);
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Show my earnings'])
        ->assertOk()->assertJsonPath('data.totals_by_currency', []);
    Http::assertSentCount(3);
});

test('Gemini failures return an error without financial answers or invoice writes', function (string $route, array $payload) {
    config(['services.gemini.key' => 'test-key']);
    Sleep::fake();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 503)]);

    $this->actingAs(User::factory()->create())->postJson(route($route), $payload)
        ->assertStatus(502)->assertExactJson(['message' => 'AI service is temporarily unavailable.']);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(3);
})->with([
    'ask' => ['ai.ask', ['question' => 'How is my business doing?']],
    'invoice' => ['ai.invoice.generate', ['prompt' => 'Invoice Mary for design work']],
]);

test('the invoice generator previews before explicit confirmation creates the invoice', function () {
    config(['services.gemini.key' => 'test-key']);
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Mary']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'intent' => 'invoice', 'customer_name' => 'Mary', 'currency' => 'USD', 'issue_date' => '2026-11-10', 'due_date' => null,
        'items' => [['description' => 'Design', 'quantity' => 2, 'unit_price' => '25.00']],
    ])]]]]]])]);

    $preview = $this->actingAs($user)->postJson(route('ai.invoice.generate'), ['prompt' => 'Invoice Mary USD 25 each for two designs on 2026-11-10'])
        ->assertOk()->assertJsonPath('draft.customer_id', $customer->id)->assertJsonPath('draft.items.0.quantity', 2);
    $this->assertDatabaseCount('invoices', 0);
    $this->postJson(route('ai.invoice.confirm'), ['confirmation_token' => $preview->json('confirmation.token')])
        ->assertOk()->assertJsonPath('invoice.total', '50.00')->assertJsonPath('invoice.status', 'draft');
    $this->assertDatabaseHas('invoices', ['user_id' => $user->id, 'customer_id' => $customer->id, 'total' => '50.00']);
    Http::assertSentCount(1);
});

test('Gemini API errors explain the required configuration or quota action', function (int $status, string $message) {
    config(['services.gemini.key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Provider details']], $status)]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'How is my business doing?'])
        ->assertStatus(502)->assertExactJson(['message' => $message]);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
})->with([
    [400, 'Gemini rejected the request. Check the configured model and request options.'],
    [401, 'Gemini access was rejected. Check the API key and project permissions.'],
    [403, 'Gemini access was rejected. Check the API key and project permissions.'],
    [404, 'The configured Gemini model is unavailable. Check GEMINI_MODEL for a supported model.'],
    [429, 'Gemini quota or rate limit exceeded. Check your project quota and try again later.'],
    [500, 'AI service is temporarily unavailable.'],
]);

test('Gemini connection failures return clear errors and do not create invoices', function () {
    config(['services.gemini.key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Invoice Mary'])
        ->assertStatus(502)->assertExactJson(['message' => 'Unable to connect to the AI service.']);
    $this->assertDatabaseCount('invoices', 0);
});

test('invalid Gemini generation output is rejected without writes', function (string $text, int $status) {
    config(['services.gemini.key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Invoice Mary'])
        ->assertStatus($status);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
})->with([['not JSON', 502], ['{}', 422], ['[]', 502]]);

test('Gemini retries transient failures with bounded exponential backoff and unchanged tool requests', function (string $failure) {
    config(['services.gemini.key' => 'test-key']);
    Sleep::fake();
    $sequence = Http::sequence();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        if ($failure === 'timeout') {
            $sequence->pushFailedConnection('cURL error 28: Operation timed out');
        } else {
            $sequence->pushStatus(503);
        }
    }
    $sequence->push(['candidates' => [['content' => ['parts' => [['text' => '{"intent":"account_read","query":"summary"}']]]]]]);
    Http::fake(function (Request $request, array $options) use ($sequence) {
        expect($options['timeout'])->toBe(8)
            ->and($options['connect_timeout'])->toBe(3)
            ->and($request['toolConfig']['functionCallingConfig']['mode'])->toBe('ANY');

        return $sequence($request, $options);
    });
    User::factory()->create()->customers()->create(['name' => 'Other account']);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertOk()->assertJsonPath('data.customers', 0)->assertJsonPath('data.total_invoices', 0);

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMilliseconds >= 500 && $duration->totalMilliseconds <= 750, 1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMilliseconds >= 1000 && $duration->totalMilliseconds <= 1250, 1);
    expect(Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->unique()->count())->toBe(1);
})->with(['HTTP 503' => 'unavailable', 'connection timeout' => 'timeout']);

test('Gemini stops after two timeout retries without creating invoice state', function () {
    config(['services.gemini.key' => 'test-key']);
    Sleep::fake();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->pushFailedConnection('cURL error 28: Operation timed out')
        ->pushFailedConnection('cURL error 28: Operation timed out')
        ->pushFailedConnection('cURL error 28: Operation timed out')
        ->pushStatus(200)]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Invoice Mary'])
        ->assertStatus(502)->assertExactJson(['message' => 'Unable to connect to the AI service.']);

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('pending_actions', 0);
});

test('Gemini stops retrying when a transient response becomes a permanent error', function (int $status) {
    config(['services.gemini.key' => 'test-key']);
    Sleep::fake();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->pushStatus(503)->pushStatus($status)->pushStatus(200)]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertStatus(502)->assertJsonStructure(['message']);

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    $this->assertDatabaseCount('pending_actions', 0);
})->with([400, 401, 403, 404]);

test('a retried invoice preview creates one draft only after confirmation without further Gemini calls', function () {
    config(['services.gemini.key' => 'test-key']);
    Sleep::fake();
    $user = User::factory()->create();
    $user->customers()->create(['name' => 'Mary']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->pushStatus(503)->push([
        'candidates' => [['content' => ['parts' => [['text' => json_encode([
            'intent' => 'invoice', 'customer_name' => 'Mary', 'currency' => 'USD', 'issue_date' => '2026-11-10', 'due_date' => null,
            'items' => [['description' => 'Design', 'quantity' => 2, 'unit_price' => '25.00']],
        ])]]]]],
    ])]);

    $preview = $this->actingAs($user)->postJson(route('ai.invoice.generate'), ['prompt' => 'Invoice Mary for two designs at USD 25 each'])
        ->assertOk()->assertJsonPath('draft.items.0.quantity', 2);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('pending_actions', 1);

    $payload = ['confirmation_token' => $preview->json('confirmation.token')];
    $this->postJson(route('ai.invoice.confirm'), $payload)->assertOk()->assertJsonPath('invoice.total', '50.00');
    $this->postJson(route('ai.invoice.confirm'), $payload)->assertOk();
    $this->assertDatabaseCount('invoices', 1);
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});
