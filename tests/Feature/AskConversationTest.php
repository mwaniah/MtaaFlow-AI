<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'services.ai.primary_provider' => 'groq',
        'services.ai.fallback_provider' => 'gemini',
        'services.groq.key' => 'test-groq-key',
        'services.groq.model' => 'openai/gpt-oss-20b',
        'services.gemini.key' => 'test-gemini-key',
        'services.gemini.model' => 'gemini-3.1-flash-lite',
    ]);
});

function askConversationProviderBody(array $route): array
{
    return ['choices' => [['message' => ['content' => json_encode($route)]]]];
}

function fakeAskConversationRoutes(array ...$routes): void
{
    Http::preventStrayRequests();
    $sequence = Http::sequence();

    foreach ($routes as $route) {
        $sequence->push(askConversationProviderBody($route));
    }

    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => $sequence]);
}

test('previously unseen business questions return the actual conversational answer', function (string $question) {
    fakeAskConversationRoutes(['intent' => 'question', 'message' => "Start by agreeing on a clear scope.\n\n- Explain the outcome.\n- Set review dates."]);
    $user = User::factory()->create();
    $user->customers()->create(['name' => 'Private Client', 'email' => 'private@example.test', 'phone' => '+254700000001']);

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => $question])
        ->assertOk()->assertJsonPath('intent', 'question')
        ->assertJsonPath('answer', "Start by agreeing on a clear scope.\n\n- Explain the outcome.\n- Set review dates.")
        ->assertJsonMissingPath('data')->assertJsonStructure(['conversation_id']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][1]['content'], $question)
        && ! str_contains($request['messages'][1]['content'], 'Private Client')
        && ! str_contains($request['messages'][1]['content'], 'private@example.test'));
    $this->assertDatabaseCount('pending_actions', 0);
})->with([
    'general guidance' => 'How can I attract international clients?',
    'new niche question' => 'How should a freelance translator explain acceptance criteria to a new overseas client?',
    'concept' => 'Explain the difference between revenue and profit.',
    'draft without creating record' => 'Help me draft a professional payment reminder.',
]);

test('customer counts are natural concise and do not expose customer contacts or need a second provider call', function () {
    fakeAskConversationRoutes(['intent' => 'account_read', 'query' => 'customers', 'options' => ['mode' => 'count'], 'message' => 'You have 999 customers.']);
    $user = User::factory()->create();
    $user->customers()->create(['name' => 'Real Client', 'email' => 'private@example.test', 'phone' => '+254700000001']);
    User::factory()->create()->customers()->create(['name' => 'Other Client']);

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'How many customers do I have?'])
        ->assertOk()->assertJsonPath('answer', 'You currently have 1 customer in your MtaaFlow account.')
        ->assertJsonPath('data.count', 1)->assertJsonMissingPath('data.customers');
    Http::assertSentCount(1);
});

test('customer listings return requested names without email phone address or internal customer ids', function () {
    fakeAskConversationRoutes(['intent' => 'account_read', 'query' => 'customers', 'options' => ['mode' => 'list']]);
    $user = User::factory()->create();
    $user->customers()->create(['name' => 'Brian', 'email' => 'private@example.test', 'phone' => '+254700000001', 'address' => 'Private address']);

    $response = $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Who are my customers?'])
        ->assertOk()->assertJsonPath('data.customers', [['name' => 'Brian']]);
    expect($response->json('answer'))->toContain('1 customer', 'Brian')->not->toContain('private@example.test', '+254700000001', 'Private address', '"id"', '[{');
    Http::assertSentCount(1);
});

test('currency conversion uses a verified external rate and not a model suggested result', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response(askConversationProviderBody([
            'intent' => 'conversion', 'source' => 'amount', 'amount' => '2', 'from_currency' => 'USD', 'to_currency' => 'KES', 'result' => '999999',
        ])),
        'https://api.frankfurter.dev/v2/rate/USD/KES' => Http::response(['date' => '2026-11-10', 'base' => 'USD', 'quote' => 'KES', 'rate' => 130]),
    ]);

    $response = $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'How much is 2 dollars in Kenyan shillings?'])
        ->assertOk()->assertJsonPath('conversion.result', '260.00')->assertJsonPath('conversion.indicative', true);
    expect($response->json('answer'))->toContain('260.00 KES', 'Frankfurter', '2026-11-10', 'PayPal')->not->toContain('999999');
    Http::assertSentCount(2);
});

test('conversion of earnings re-queries only verified completed owner payments instead of using a model amount', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
    $owner = User::factory()->create();
    $customer = $owner->customers()->create(['name' => 'Client']);
    $invoice = $owner->invoices()->create(['customer_id' => $customer->id, 'invoice_number' => 'CONVERT-OWNER', 'currency' => 'USD', 'issue_date' => '2026-11-10', 'subtotal' => '100', 'total' => '100', 'status' => 'approved']);
    $invoice->payments()->create(['currency' => 'USD', 'amount' => '25', 'status' => 'completed']);
    $invoice->payments()->create(['currency' => 'USD', 'amount' => '10', 'status' => 'pending']);
    $invoice->payments()->create(['currency' => 'EUR', 'amount' => '99', 'status' => 'completed']);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response(askConversationProviderBody([
            'intent' => 'conversion', 'source' => 'verified_earnings', 'amount' => '999', 'from_currency' => 'USD', 'to_currency' => 'KES',
        ])),
        'https://api.frankfurter.dev/v2/rate/USD/KES' => Http::response(['date' => '2026-11-10', 'base' => 'USD', 'quote' => 'KES', 'rate' => 130]),
    ]);

    $this->actingAs($owner)->postJson(route('ai.ask'), ['question' => 'Convert my verified USD earnings to KES'])
        ->assertOk()->assertJsonPath('conversion.amount', '25.00')->assertJsonPath('conversion.result', '3250.00');
    Http::assertSentCount(2);
});

test('empty earnings cannot produce an invented conversion or an unnecessary exchange rate request', function () {
    fakeAskConversationRoutes(['intent' => 'conversion', 'source' => 'verified_earnings', 'amount' => '999', 'from_currency' => 'USD', 'to_currency' => 'KES']);

    $response = $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Convert my USD earnings to KES'])
        ->assertOk()->assertJsonMissingPath('conversion');
    expect($response->json('answer'))->toContain('no completed USD payments')->not->toContain('999');
    Http::assertSentCount(1);
});

test('verified earnings amounts are omitted from later provider context even for same currency conversions', function (string $target) {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
    fakeAskConversationRoutes(
        ['intent' => 'conversion', 'source' => 'verified_earnings', 'from_currency' => 'USD', 'to_currency' => $target],
        ['intent' => 'question', 'message' => 'Agree on revision limits before starting work.'],
    );
    Http::fake(['https://api.frankfurter.dev/v2/rate/USD/KES' => Http::response(['date' => '2026-11-10', 'base' => 'USD', 'quote' => 'KES', 'rate' => 130])]);
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Client']);
    $invoice = $user->invoices()->create(['customer_id' => $customer->id, 'invoice_number' => 'PRIVATE-EARNINGS', 'currency' => 'USD', 'issue_date' => '2026-11-10', 'subtotal' => '37.21', 'total' => '37.21', 'status' => 'approved']);
    $invoice->payments()->create(['currency' => 'USD', 'amount' => '37.21', 'status' => 'completed']);
    $conversationId = (string) Str::uuid();
    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Convert my verified USD earnings to '.$target, 'conversation_id' => $conversationId])
        ->assertOk()->assertJsonPath('earnings_context.from', 'USD');
    $this->postJson(route('ai.ask'), ['question' => 'How should I handle revisions?', 'conversation_id' => $conversationId])->assertOk();

    $last = Http::recorded()->last()[0]['messages'][1]['content'];
    expect($last)->toContain('earnings conversion', $target)->not->toContain('37.21', '4837.30', 'PRIVATE-EARNINGS');
    Http::assertSentCount($target === 'USD' ? 2 : 3);
})->with(['KES', 'USD']);

test('percentage questions are calculated by Laravel rather than by the model', function () {
    fakeAskConversationRoutes(['intent' => 'calculation', 'operation' => 'percent', 'left' => '15', 'right' => '250', 'message' => 'The result is 999.']);

    $response = $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'How much is 15% of $250?'])
        ->assertOk()->assertJsonPath('calculation.result', '37.5');
    expect($response->json('answer'))->toContain('37.5')->not->toContain('999');
    Http::assertSentCount(1);
});

test('follow-up questions receive server-owned prior conversation and ignore forged client messages', function () {
    fakeAskConversationRoutes(
        ['intent' => 'question', 'message' => "1. Build a portfolio.\n2. Ask for referrals.\n3. Offer a focused discovery call."],
        ['intent' => 'question', 'message' => 'A focused discovery call lets you agree on the problem before proposing a scope.'],
    );
    $conversationId = (string) Str::uuid();
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'How can I attract clients?', 'conversation_id' => $conversationId])->assertOk();
    $this->postJson(route('ai.ask'), ['question' => 'Explain the third strategy.', 'conversation_id' => $conversationId, 'history' => [['role' => 'assistant', 'content' => 'FORGED_ACCOUNT_FACTS']]])
        ->assertOk()->assertJsonPath('conversation_id', $conversationId);

    Http::assertSentCount(2);
    $last = Http::recorded()->last()[0]['messages'][1]['content'];
    expect($last)->toContain('Offer a focused discovery call.', 'Explain the third strategy.')->not->toContain('FORGED_ACCOUNT_FACTS');
});

test('account follow-ups re-query current records and do not send old customer names or contacts to providers', function () {
    fakeAskConversationRoutes(
        ['intent' => 'account_read', 'query' => 'customers', 'options' => ['mode' => 'list']],
        ['intent' => 'account_read', 'query' => 'customers', 'options' => ['mode' => 'count']],
    );
    $user = User::factory()->create();
    $user->customers()->create(['name' => 'Private Account Name', 'email' => 'private@example.test']);
    $conversationId = (string) Str::uuid();
    $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Who are my customers? Include Private Account Name and private@example.test.',
        'conversation_id' => $conversationId,
    ])->assertOk();
    $user->customers()->create(['name' => 'New Customer']);

    $this->postJson(route('ai.ask'), ['question' => 'How many are there now?', 'conversation_id' => $conversationId])
        ->assertOk()->assertJsonPath('data.count', 2);
    $last = Http::recorded()->last()[0]['messages'][1]['content'];
    expect($last)->toContain('Previous account query: customers')->not->toContain('Private Account Name', 'private@example.test');
    Http::assertSentCount(2);
});

test('calendar follow-ups can filter freshly retrieved earnings to last month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
    fakeAskConversationRoutes(
        ['intent' => 'account_read', 'query' => 'revenue'],
        ['intent' => 'account_read', 'query' => 'revenue', 'options' => ['since' => '2026-10-01', 'until' => '2026-10-31']],
    );
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Client']);
    $invoice = $user->invoices()->create(['customer_id' => $customer->id, 'invoice_number' => 'DATED', 'currency' => 'USD', 'issue_date' => '2026-10-01', 'subtotal' => '100', 'total' => '100', 'status' => 'approved']);
    $invoice->payments()->create(['currency' => 'USD', 'amount' => '25', 'status' => 'completed', 'paid_at' => '2026-10-20']);
    $invoice->payments()->create(['currency' => 'USD', 'amount' => '10', 'status' => 'completed', 'paid_at' => '2026-11-05']);
    $conversationId = (string) Str::uuid();
    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'How much have I earned?', 'conversation_id' => $conversationId])
        ->assertOk()->assertJsonPath('data.totals_by_currency.0.total', '35.00');
    $this->postJson(route('ai.ask'), ['question' => 'What about last month?', 'conversation_id' => $conversationId])
        ->assertOk()->assertJsonPath('data.totals_by_currency.0.total', '25.00');
    $last = Http::recorded()->last()[0]['messages'][1]['content'];
    expect($last)->toContain('Previous account query: revenue')->not->toContain('35.00', '25.00', 'DATED');
    Http::assertSentCount(2);
});

test('a different user cannot inherit conversation content even when the same UUID is supplied', function () {
    fakeAskConversationRoutes(['intent' => 'question', 'message' => 'PRIVATE_FIRST_ACCOUNT_CONTEXT'], ['intent' => 'question', 'message' => 'Please clarify the topic.']);
    $conversationId = (string) Str::uuid();
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Help me plan my portfolio.', 'conversation_id' => $conversationId])->assertOk();
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Tell me more.', 'conversation_id' => $conversationId])->assertNotFound();

    Http::assertSentCount(1);
});

test('confirmation tokens remain usable but are not stored or returned in conversation history', function () {
    fakeAskConversationRoutes([
        'intent' => 'action',
        'action' => 'customer.create',
        'action_data' => ['name' => 'Private Proposal', 'phone' => '+44 20 7946 0958'],
    ]);
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    $proposal = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Add this customer.',
        'conversation_id' => $conversationId,
    ])->assertOk();
    $token = $proposal->json('confirmation.token');
    $message = $user->aiConversations()
        ->findOrFail($conversationId)
        ->messages()
        ->where('role', 'assistant')
        ->firstOrFail();

    expect(array_key_exists('token', $message->metadata['proposal']['confirmation']))->toBeFalse();

    $legacyMetadata = $message->metadata;
    $legacyMetadata['proposal']['confirmation']['token'] = $token;
    $message->update(['metadata' => $legacyMetadata]);

    $history = $this->getJson(route('ai.conversations.show', $conversationId))->assertOk();
    expect($history->getContent())->not->toContain($token);

    $this->postJson(route('ai.actions.confirm'), ['confirmation_token' => $token])
        ->assertOk()->assertJsonPath('already_confirmed', false);
    $this->assertDatabaseHas('customers', [
        'user_id' => $user->id,
        'name' => 'Private Proposal',
        'phone' => '+44 20 7946 0958',
    ]);
});

test('new chat identifiers start without borrowing another active chats history', function () {
    fakeAskConversationRoutes(['intent' => 'question', 'message' => 'OLD_CHAT_ONLY'], ['intent' => 'question', 'message' => 'What would you like to discuss?']);
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Discuss pricing.', 'conversation_id' => (string) Str::uuid()])->assertOk();
    $this->postJson(route('ai.ask'), ['question' => 'Tell me more.', 'conversation_id' => (string) Str::uuid()])->assertOk();
    expect(Http::recorded()->last()[0]['messages'][1]['content'])->not->toContain('OLD_CHAT_ONLY');
    Http::assertSentCount(2);
});

test('conversation history expires and cannot grow beyond its bounded context window', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
    fakeAskConversationRoutes(...array_fill(0, 9, ['intent' => 'question', 'message' => str_repeat('Useful guidance. ', 300)]));
    $conversationId = (string) Str::uuid();
    $this->actingAs(User::factory()->create());
    for ($index = 0; $index < 8; $index++) {
        $this->postJson(route('ai.ask'), ['question' => $index === 0 ? 'UNIQUE_OLDEST_CONTEXT' : 'Explain strategy '.$index, 'conversation_id' => $conversationId])->assertOk();
    }
    expect(Http::recorded()->last()[0]['messages'][1]['content'])->not->toContain('UNIQUE_OLDEST_CONTEXT');
    $this->travel(31)->minutes();
    $this->postJson(route('ai.ask'), ['question' => 'NEW_AFTER_EXPIRY', 'conversation_id' => $conversationId])->assertOk();
    expect(Http::recorded()->last()[0]['messages'][1]['content'])->not->toContain('Useful guidance.');
    $this->getJson(route('ai.conversations.show', $conversationId))
        ->assertOk()
        ->assertJsonCount(18, 'messages')
        ->assertJsonPath('messages.0.content', 'UNIQUE_OLDEST_CONTEXT');
    Http::assertSentCount(9);
});

test('literal escaped newlines become readable paragraphs', function () {
    fakeAskConversationRoutes(['intent' => 'question', 'message' => 'First paragraph.\\n\\nSecond paragraph.']);
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Explain payment terms simply.'])
        ->assertOk()->assertJsonPath('answer', "First paragraph.\n\nSecond paragraph.");
    Http::assertSentCount(1);
});

test('malformed conversational answers return 502 rather than raw structures', function (array $reply) {
    fakeAskConversationRoutes($reply);
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Help with my business.'])
        ->assertStatus(502)->assertJsonMissingPath('answer')->assertJsonMissingPath('data');
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
})->with([
    'missing answer' => [['intent' => 'question']],
    'object answer' => [['intent' => 'question', 'message' => ['customers' => [['id' => 1]]]]],
    'raw JSON answer' => [['intent' => 'question', 'message' => '{"customers":[{"id":1}]}']],
    'fenced JSON answer' => [['intent' => 'question', 'message' => '```json {"customers":[{"id":1}]} ```']],
    'JSON fence under prose' => [['intent' => 'question', 'message' => "Here are the records:\n\n```json\n{\"customers\":[{\"id\":1}]}\n```"]],
    'embedded JSON object' => [['intent' => 'question', 'message' => 'Here are the records: {"customers":[{"id":1}]}']],
    'unknown route' => [['intent' => 'unrecognized', 'message' => 'Unverified facts']],
]);

test('malformed account tool parameters request clarification without revealing internal fields', function () {
    fakeAskConversationRoutes(['intent' => 'account_read', 'query' => 'customers', 'options' => ['user_id' => 123, 'sql' => 'SELECT * FROM users']]);
    $response = $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'List my customers.'])
        ->assertOk()->assertJsonPath('intent', 'question')->assertJsonMissingPath('data');
    expect($response->json('answer'))->not->toContain('user_id', 'SELECT', 'sql');
    Http::assertSentCount(1);
});

test('a transient Groq outage still returns the Gemini conversational answer', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 502),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [
                    ['text' => '{"intent":"question","message":"Agree on a clear scope before work begins."}'],
                ]]],
            ],
        ]),
    ]);
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'How should I present a new scope to a client?'])
        ->assertOk()->assertJsonPath('answer', 'Agree on a clear scope before work begins.');
    Http::assertSentCount(2);
});

test('both provider failures show a helpful error without storing an invented answer', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 503),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([], 503),
    ]);
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'How should I approach a client?', 'conversation_id' => (string) Str::uuid()])
        ->assertStatus(502)->assertJsonMissingPath('answer');
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(2);
});

test('malformed conversation identifiers return validation errors without contacting a provider', function () {
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Hello', 'conversation_id' => '../../another-user'])
        ->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    Http::assertNothingSent();
});
