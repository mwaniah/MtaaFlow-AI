<?php

use App\Actions\AI\AskAssistant;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'services.ai.primary_provider' => 'groq', 'services.ai.fallback_provider' => 'gemini',
        'services.groq.key' => 'test-groq-key', 'services.groq.model' => 'openai/gpt-oss-20b',
        'services.gemini.key' => 'test-gemini-key', 'services.gemini.model' => 'gemini-3.1-flash-lite',
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
});

function assistantToolResponse(string $name, array $arguments): array
{
    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
        'id' => 'tool-test', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode((object) $arguments)],
    ]]]]]];
}

test('native customer tools request missing phone details in each supported language without requiring email', function (string $locale, string $question) {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('prepare_account_action', ['action' => 'customer.create', 'action_data' => ['name' => 'Brian']]))]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => $question, 'locale' => $locale])
        ->assertOk()->assertJsonPath('intent', 'action')->assertJsonPath('action', 'customer.create')
        ->assertJsonValidationErrors('phone')->assertJsonMissingPath('errors.email')->assertJsonMissingPath('confirmation');

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][1]['content'], 'Response language preference: '.$locale)
        && str_contains($request['messages'][1]['content'], $question));
})->with([
    'English' => ['en', 'Add a customer named Brian.'],
    'French' => ['fr', 'Ajoute un client nommé Brian.'],
    'German' => ['de', 'Füge einen Kunden namens Brian hinzu.'],
    'Spanish' => ['es', 'Agrega un cliente llamado Brian.'],
]);

test('native new product tools prepare initial inventory without requiring an existing product', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('prepare_account_action', ['action' => 'product.create', 'action_data' => ['name' => 'Watch', 'quantity' => 6]]))]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Add 6 watches as products into my inventory.'])
        ->assertOk()->assertJsonPath('action', 'product.create')->assertJsonPath('preview.product.name', 'Watch')
        ->assertJsonPath('preview.product.quantity', 6)->assertJsonStructure(['confirmation' => ['token']]);

    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('inventory_adjustments', 0);
    $this->assertDatabaseCount('pending_actions', 1);
    Http::assertSentCount(1);
});

test('native existing stock tools do not silently create a missing product', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('prepare_account_action', ['action' => 'inventory.adjust', 'action_data' => ['product_name' => 'Watch', 'quantity_change' => 10]]))]);

    $response = $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Increase Watch stock by 10.'])
        ->assertOk()->assertJsonPath('action', 'inventory.adjust')->assertJsonMissingPath('confirmation');

    expect($response->json('answer'))->toContain('No product');
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
});

test('a malformed native tool response is repaired once before preparing any business write', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
        ->push(['choices' => [['message' => ['tool_calls' => [['function' => ['name' => 'prepare_account_action', 'arguments' => '{invalid']]]]]]])
        ->push(assistantToolResponse('prepare_account_action', ['action' => 'customer.create', 'action_data' => ['name' => 'Brian', 'phone' => '+44 7700 900123']]))]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Add Brian with phone +44 7700 900123.'])
        ->assertOk()->assertJsonPath('preview.customer.name', 'Brian')->assertJsonPath('preview.customer.phone', '+44 7700 900123')
        ->assertJsonStructure(['confirmation' => ['token']]);

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('pending_actions', 1);
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

test('provider rejected tool generation is repaired once without retrying business execution', function (string $code) {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::sequence()
        ->push(['error' => ['code' => $code, 'failed_generation' => 'Untrusted provider output is never executed.']], 400)
        ->push(assistantToolResponse('prepare_account_action', ['action' => 'product.create', 'action_data' => ['name' => 'Watch', 'quantity' => 6]]))]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Add 6 watches as products into my inventory.'])
        ->assertOk()->assertJsonPath('action', 'product.create')->assertJsonPath('preview.product.quantity', 6);

    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('pending_actions', 1);
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
})->with(['tool_use_failed', 'json_validate_failed']);

test('repeated malformed native tools return 502 without writes or uncontrolled retries', function (array $body) {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response($body)]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Create a customer.'])
        ->assertStatus(502)->assertJsonMissingPath('answer')->assertJsonMissingPath('confirmation');

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
})->with([
    'undeclared tool' => [assistantToolResponse('execute_sql', ['query' => 'DELETE FROM users'])],
    'invalid argument type' => [assistantToolResponse('prepare_account_action', ['action' => 'customer.create', 'action_data' => 'not an object'])],
    'arbitrary root argument' => [assistantToolResponse('read_account', ['query' => 'customers', 'sql' => 'SELECT * FROM users'])],
    'arbitrary nested argument' => [assistantToolResponse('prepare_account_action', ['action' => 'customer.create', 'action_data' => ['name' => 'Brian', 'user_id' => 123]])],
    'JSON list arguments' => [['choices' => [['message' => ['tool_calls' => [['function' => ['name' => 'read_account', 'arguments' => '[]']]]]]]]],
    'malformed argument JSON' => [['choices' => [['message' => ['tool_calls' => [['function' => ['name' => 'read_account', 'arguments' => '{invalid']]]]]]]],
    'multiple calls' => [['choices' => [['message' => ['tool_calls' => [
        ['function' => ['name' => 'prepare_account_action', 'arguments' => '{"action":"customer.create"}']],
        ['function' => ['name' => 'prepare_account_action', 'arguments' => '{"action":"customer.create"}']],
    ]]]]]],
]);

test('native action tools with missing arguments request clarification without an unreadable response', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('prepare_account_action', ['action' => 'customer.create']))]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Create a customer.'])
        ->assertOk()->assertJsonValidationErrors(['name', 'phone'])->assertJsonMissingPath('errors.email')->assertJsonMissingPath('confirmation');

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
});

test('Gemini native fallback uses the same declared tools and never executes a prepared action', function () {
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 503),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'Internal reasoning should not appear.', 'thought' => true],
                ['functionCall' => ['name' => 'prepare_account_action', 'args' => ['action' => 'product.create', 'action_data' => ['name' => 'Watch', 'quantity' => 6]]]],
            ]]]],
        ]),
    ]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Create a product Watch with quantity 6.'])
        ->assertOk()->assertJsonPath('action', 'product.create')->assertJsonPath('preview.product.quantity', 6);

    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('pending_actions', 1);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com')
        && $request['toolConfig']['functionCallingConfig']['mode'] === 'ANY'
        && in_array('prepare_account_action', $request['toolConfig']['functionCallingConfig']['allowedFunctionNames'], true)
        && collect($request['tools'][0]['functionDeclarations'])->contains(fn (array $tool): bool => $tool['name'] === 'prepare_account_action' && isset($tool['parametersJsonSchema']['properties']['action_data'])));
    Sleep::assertNeverSlept();
});

test('native read tools return verified owner data and preserve query filters for later context', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('read_account', ['query' => 'customers', 'options' => ['mode' => 'count']]))]);
    $user = User::factory()->create();
    $user->customers()->create(['name' => 'Brian']);
    User::factory()->create()->customers()->create(['name' => 'Other private customer']);

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'How many customers do I have?'])
        ->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('options.mode', 'count')
        ->assertJsonPath('answer', 'You currently have 1 customer in your MtaaFlow account.');

    Http::assertSentCount(1);
});

test('native read tools missing the query request clarification without executing any query', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('read_account', []))]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Show those.'])
        ->assertOk()->assertJsonPath('intent', 'question')->assertJsonMissingPath('data');

    Http::assertSentCount(1);
});

test('native confirmation tools return only a decision without executing business changes', function (string $decision) {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('action_confirmation', ['decision' => $decision]))]);
    $user = User::factory()->create();

    $result = app(AskAssistant::class)->respond($user, $decision === 'confirm' ? 'Yes, confirm.' : 'Cancel that.', []);

    expect($result)->toBe(['intent' => 'confirmation', 'decision' => $decision]);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
})->with(['confirm', 'cancel']);

test('native invoice tools request missing lines without inventing invoice data', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(assistantToolResponse('prepare_invoice', ['customer_name' => 'Brian']))]);
    $user = User::factory()->create();
    $user->customers()->create(['name' => 'Brian']);

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Create an invoice for Brian.'])
        ->assertOk()->assertJsonPath('intent', 'invoice')->assertJsonPath('draft.customer_name', 'Brian')
        ->assertJsonPath('draft.items', [])->assertJsonMissingPath('confirmation');

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
});
