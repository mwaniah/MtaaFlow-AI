<?php

use App\Actions\AI\ProviderManager;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'services.ai.primary_provider' => 'groq',
        'services.ai.fallback_provider' => 'gemini',
        'services.ai.fallback_on_rate_limit' => true,
        'services.groq.key' => 'groq-test-key',
        'services.groq.model' => 'llama-3.3-70b-versatile',
        'services.gemini.key' => 'gemini-test-key',
        'services.gemini.model' => 'gemini-3.1-flash-lite',
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
});

test('Groq returns account results with native tools and Gemini is not called', function () {
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => function (Request $request, array $options) {
            expect($options['connect_timeout'])->toBe(3)
                ->and($options['timeout'])->toBe(8);

            return Http::response(['choices' => [['message' => ['content' => '{"intent":"account_read","query":"summary"}']]]]);
        },
    ]);

    $this->actingAs(User::factory()->unverified()->create())
        ->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertOk()
        ->assertJsonPath('intent', 'account_read')
        ->assertJsonPath('data.total_invoices', 0);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer groq-test-key')
        && $request['model'] === 'llama-3.3-70b-versatile'
        && $request['tool_choice'] === 'required'
        && $request['parallel_tool_calls'] === false
        && ! isset($request['response_format'])
        && collect($request['tools'])->contains(fn (array $tool): bool => $tool['function']['name'] === 'read_account')
        && collect($request['messages'])->contains(fn (array $message): bool => $message['role'] === 'user'
            && str_contains($message['content'], 'Summarize my account')));
    Sleep::assertNeverSlept();
});

test('temporary Groq HTTP failures fall back once with the same prompt and bounded timeouts', function (int $status) {
    $groqPrompt = null;
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => function (Request $request, array $options) use ($status, &$groqPrompt) {
            expect($options['timeout'])->toBe(8)
                ->and($options['connect_timeout'])->toBe(3);
            $groqPrompt = collect($request['messages'])->firstWhere('role', 'user')['content'];

            return Http::response(['error' => ['message' => 'Temporary provider failure']], $status, ['Retry-After' => '1']);
        },
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => function (Request $request, array $options) use (&$groqPrompt) {
            expect($options['timeout'])->toBe(8)
                ->and($options['connect_timeout'])->toBe(3)
                ->and($request['contents'][0]['parts'][0]['text'])->toBe($groqPrompt)
                ->and($request['toolConfig']['functionCallingConfig']['mode'])->toBe('ANY');
            expect(collect($request['tools'][0]['functionDeclarations'])->pluck('name'))->toContain('read_account');
            expect($request->hasHeader('x-goog-api-key', 'gemini-test-key'))->toBeTrue();

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"intent":"account_read","query":"summary"}']]]]]]);
        },
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertOk()->assertJsonPath('data.total_invoices', 0);

    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
})->with([
    'rate limited' => 429,
    'internal error' => 500,
    'bad gateway' => 502,
    'unavailable' => 503,
    'gateway timeout' => 504,
]);

test('Groq rate limits do not fall back when the rate limit policy is disabled', function () {
    config(['services.ai.fallback_on_rate_limit' => false]);
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([], 429)]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertStatus(502);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('temporary Groq connection failures fall back without retrying Groq', function (int $curlError) {
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::failedConnection('cURL error '.$curlError.': Temporary network failure'),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"intent":"account_read","query":"summary"}']]]]],
        ]),
    ]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertOk()->assertJsonPath('data.customers', 0);

    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
})->with([
    'DNS resolution' => 6,
    'connect failed' => 7,
    'timeout' => 28,
    'empty response' => 52,
    'send failure' => 55,
    'receive failure' => 56,
]);

test('permanent Groq errors return 502 without Gemini fallback', function (int $status) {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'error' => ['message' => 'A sensitive provider diagnostic must not be exposed'],
    ], $status)]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'AI provider configuration needs attention. Please contact support.']);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    $this->assertDatabaseCount('pending_actions', 0);
    $this->assertDatabaseCount('invoices', 0);
})->with([
    'bad request' => 400,
    'invalid credentials' => 401,
    'forbidden key' => 403,
    'unsupported model' => 404,
    'invalid provider options' => 422,
]);

test('missing Groq configuration returns 503 without using Gemini', function (string $configuration) {
    config([$configuration => null]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'AI provider configuration is incomplete. Please contact support.']);

    Http::assertNothingSent();
    Sleep::assertNeverSlept();
})->with([
    'API key' => 'services.groq.key',
    'model' => 'services.groq.model',
]);

test('two unavailable providers return 502 after only one attempt each', function () {
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 503),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([], 503),
    ]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'AI services are temporarily unavailable. Please try again later.']);

    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
    $this->assertDatabaseCount('pending_actions', 0);
    $this->assertDatabaseCount('invoices', 0);
});

test('invalid Groq JSON returns 502 without fallback or invoice state', function (string $content) {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => $content]]],
    ])]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'AI service returned an invalid response.']);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    $this->assertDatabaseCount('pending_actions', 0);
    $this->assertDatabaseCount('invoices', 0);
})->with([
    'malformed JSON' => 'not JSON',
    'JSON list' => '[]',
]);

test('missing Groq completion text returns 502 without fallback or invoice state', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => []])]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice'])
        ->assertStatus(502);

    Http::assertSentCount(1);
    $this->assertDatabaseCount('pending_actions', 0);
    $this->assertDatabaseCount('invoices', 0);
});

test('incomplete Groq domain output returns existing validation without fallback', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '{}']]],
    ])]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'AI service returned an incomplete invoice draft.']);

    Http::assertSentCount(1);
    $this->assertDatabaseCount('pending_actions', 0);
    $this->assertDatabaseCount('invoices', 0);
});

test('Groq interpretation cannot replace authenticated financial records with generated statistics', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    foreach ([$owner, $otherUser] as $index => $user) {
        $customer = $user->customers()->create(['name' => $index === 0 ? 'Owner client' : 'Private other client']);
        $invoice = $user->invoices()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'GROQ-REVENUE-'.$index,
            'currency' => 'USD',
            'issue_date' => '2026-11-10',
            'subtotal' => '100',
            'total' => '100',
            'status' => 'approved',
        ]);
        $invoice->payments()->create(['currency' => 'USD', 'amount' => $index === 0 ? '25' : '75', 'status' => 'completed']);
        $invoice->payments()->create(['currency' => 'USD', 'amount' => '10', 'status' => 'pending']);
        $invoice->payments()->create(['currency' => 'EUR', 'amount' => '999', 'status' => 'completed']);
    }
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '{"intent":"account_read","query":"revenue","message":"Your revenue is USD 999999","data":{"totals_by_currency":[{"currency":"USD","total":"999999.00"}]}}']]],
    ])]);

    $this->actingAs($owner)->postJson(route('ai.ask'), ['question' => 'Show my completed payment totals'])
        ->assertOk()
        ->assertJsonPath('answer', 'Your verified earnings from completed payments are USD 25.00.')
        ->assertJsonPath('data.totals_by_currency', [['currency' => 'USD', 'total' => '25.00']])
        ->assertJsonMissing(['total' => '999999.00'])
        ->assertJsonMissing(['total' => '75.00']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ! str_contains(json_encode($request->data()), 'Private other client'));
    $this->assertDatabaseCount('pending_actions', 0);
});

test('Groq account summaries for empty users contain only recorded zero counts', function () {
    User::factory()->create()->customers()->create(['name' => 'Private other customer']);
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '{"intent":"account_read","query":"summary","message":"Revenue grew by 90 percent","customers":100}']]],
    ])]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'How is my business doing?'])
        ->assertOk()
        ->assertJsonPath('answer', fn (string $answer): bool => str_starts_with($answer, 'Your business summary:') && ! str_contains($answer, '90 percent'))
        ->assertJsonPath('data', [
            'query' => 'summary', 'customers' => 0, 'total_invoices' => 0, 'paid_invoices' => 0,
            'outstanding_invoices' => 0, 'totals_by_currency' => [], 'products' => 0,
            'low_stock_products' => 0, 'approved_invoices' => 0, 'reminders' => 0,
        ]);

    Http::assertSentCount(1);
});

test('provider invoice previews require owner confirmation and repeated confirmation saves only once', function (bool $fallback) {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Mary']);
    $otherUser = User::factory()->create();
    $otherUser->customers()->create(['name' => 'Mary']);
    $invoiceJson = json_encode([
        'intent' => 'invoice', 'message' => 'Review this draft.', 'customer_name' => 'Mary', 'currency' => 'USD',
        'issue_date' => '2026-11-10', 'due_date' => null,
        'items' => [['description' => 'Design', 'quantity' => 2, 'unit_price' => '25.00']],
    ]);
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => $fallback
            ? Http::response([], 503)
            : Http::response(['choices' => [['message' => ['content' => $invoiceJson]]]]),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $invoiceJson]]]]],
        ]),
    ]);

    $preview = $this->actingAs($user)->postJson(route('ai.invoice.generate'), ['prompt' => 'Invoice Mary USD 25 each for two designs issued 2026-11-10']);

    $preview->assertOk()
        ->assertJsonPath('draft.customer_id', $customer->id)
        ->assertJsonPath('draft.currency', 'USD')
        ->assertJsonPath('draft.items.0.quantity', 2)
        ->assertJsonPath('draft.items.0.unit_price', '25.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('pending_actions', 1);
    $token = $preview->json('confirmation.token');
    $this->actingAs($otherUser)->postJson(route('ai.invoice.confirm'), ['confirmation_token' => $token])->assertNotFound();
    $this->assertDatabaseCount('invoices', 0);

    $confirmation = $this->actingAs($user)->postJson(route('ai.invoice.confirm'), ['confirmation_token' => $token]);

    $confirmation->assertOk()
        ->assertJsonPath('invoice.currency', 'USD')
        ->assertJsonPath('invoice.total', '50.00')
        ->assertJsonPath('invoice.status', 'draft');
    $this->postJson(route('ai.invoice.confirm'), ['confirmation_token' => $token])
        ->assertOk()->assertJsonPath('invoice_id', $confirmation->json('invoice_id'));
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseHas('invoices', ['user_id' => $user->id, 'customer_id' => $customer->id, 'total' => '50.00', 'status' => 'draft']);
    $this->assertDatabaseHas('invoice_items', ['invoice_id' => $confirmation->json('invoice_id'), 'quantity' => 2, 'unit_price' => '25.00', 'amount' => '50.00']);
    Http::assertSentCount($fallback ? 2 : 1);
    Sleep::assertNeverSlept();
})->with(['Groq success' => false, 'Gemini fallback' => true]);
test('a permanent Groq TLS failure does not use the fallback provider', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::failedConnection('cURL error 60: SSL certificate problem')]);

    $this->actingAs(User::factory()->create())->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertStatus(502);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('provider fallback logs identify the handling provider without credentials or private prompts', function () {
    Log::spy();
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Sensitive provider body groq-test-key confidential-business-context-987'],
        ], 503),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"intent":"account_read","query":"summary"}']]]]],
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), ['question' => 'Summarize confidential-business-context-987'])
        ->assertOk();

    Log::shouldHaveReceived('warning')->with('AI provider request failed', Mockery::on(function (array $context): bool {
        $serialized = json_encode($context);

        return $context['provider'] === 'groq'
            && $context['successful'] === false
            && ! str_contains($serialized, 'groq-test-key')
            && ! str_contains($serialized, 'confidential-business-context-987');
    }))->once();
    Log::shouldHaveReceived('info')->with('AI provider request completed', Mockery::on(function (array $context): bool {
        $serialized = json_encode($context);

        return $context['provider'] === 'gemini'
            && $context['fallback'] === true
            && $context['successful'] === true
            && ! str_contains($serialized, 'gemini-test-key')
            && ! str_contains($serialized, 'confidential-business-context-987');
    }))->once();
    Http::assertSentCount(2);
});

test('a Groq 502 falls back to Gemini 200 with correlated diagnostics and no internal result metadata', function () {
    Log::spy();
    $content = '{"intent":"account_read","query":"summary"}';
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response(['error' => ['code' => 'internal_server_error']], 502),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $content]]]]],
        ]),
    ]);

    $result = app(ProviderManager::class)->generate('Return a JSON account summary intent.', true);

    expect($result)->toBe(['text' => $content]);
    $requestId = null;
    Log::shouldHaveReceived('warning')->with('AI provider request failed', Mockery::on(function (array $context) use (&$requestId): bool {
        $requestId = $context['request_id'];
        expect($requestId)->toBeUuid();

        return $context['environment'] === 'testing'
            && $context['provider'] === 'groq'
            && $context['fallback'] === false
            && $context['successful'] === false
            && $context['response_status'] === 502
            && $context['upstream_status'] === 502
            && $context['failure_reason'] === 'upstream_unavailable'
            && $context['fallback_decision'] === 'attempt';
    }))->once();
    Log::shouldHaveReceived('info')->with('AI provider fallback attempted', Mockery::on(fn (array $context): bool => $context['request_id'] === $requestId
        && $context['from_provider'] === 'groq'
        && $context['to_provider'] === 'gemini'
        && $context['reason'] === 'upstream_unavailable'))->once();
    Log::shouldHaveReceived('info')->with('AI provider request completed', Mockery::on(fn (array $context): bool => $context['request_id'] === $requestId
        && $context['provider'] === 'gemini'
        && $context['fallback'] === true
        && $context['successful'] === true
        && $context['upstream_status'] === 200))->once();
    Log::shouldNotHaveReceived('warning', ['AI request failed', Mockery::any()]);
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

test('a Groq 502 followed by Gemini 503 logs both provider failures and the failed fallback outcome', function () {
    Log::spy();
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([], 502),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([], 503),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft an invoice'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'AI services are temporarily unavailable. Please try again later.']);

    Log::shouldHaveReceived('warning')->with('AI provider request failed', Mockery::on(fn (array $context): bool => $context['provider'] === 'groq'
        && $context['response_status'] === 502
        && $context['upstream_status'] === 502))->once();
    Log::shouldHaveReceived('warning')->with('AI provider request failed', Mockery::on(fn (array $context): bool => $context['provider'] === 'gemini'
        && $context['response_status'] === 502
        && $context['upstream_status'] === 503
        && $context['fallback'] === true))->once();
    Log::shouldHaveReceived('warning')->with('AI request failed', Mockery::on(fn (array $context): bool => $context['fallback_attempted'] === true
        && $context['failure_reason'] === 'both_providers_failed'
        && $context['primary_failure_reason'] === 'upstream_unavailable'
        && $context['fallback_failure_reason'] === 'upstream_unavailable'))->once();
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
    $this->assertDatabaseCount('pending_actions', 0);
    $this->assertDatabaseCount('invoices', 0);
});

test('an unavailable Groq model is logged as upstream 404 rather than a transient 502', function () {
    Log::spy();
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'model_not_found', 'message' => 'The requested model does not exist or you do not have access.'],
        ], 404),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'AI provider configuration needs attention. Please contact support.']);

    Log::shouldHaveReceived('warning')->with('AI provider request failed', Mockery::on(fn (array $context): bool => $context['provider'] === 'groq'
        && $context['response_status'] === 502
        && $context['upstream_status'] === 404
        && $context['failure_reason'] === 'model_unavailable'
        && $context['fallback_decision'] === 'permanent_error'
        && $context['upstream_error_code'] === 'model_not_found'))->once();
    Log::shouldHaveReceived('warning')->with('AI request failed', Mockery::on(fn (array $context): bool => $context['fallback_attempted'] === false
        && $context['failure_reason'] === 'model_unavailable'))->once();
    Log::shouldNotHaveReceived('info', ['AI provider fallback attempted', Mockery::any()]);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('missing Groq credentials log a configuration failure without claiming an upstream HTTP response', function () {
    Log::spy();
    config(['services.groq.key' => null]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'AI provider configuration is incomplete. Please contact support.']);

    Log::shouldHaveReceived('warning')->with('AI provider request failed', Mockery::on(fn (array $context): bool => $context['provider'] === 'groq'
        && $context['response_status'] === 503
        && $context['upstream_status'] === null
        && $context['failure_reason'] === 'configuration_missing'
        && $context['fallback_decision'] === 'configuration_missing'))->once();
    Log::shouldHaveReceived('warning')->with('AI request failed', Mockery::on(fn (array $context): bool => $context['fallback_attempted'] === false
        && $context['failure_reason'] === 'configuration_missing'
        && $context['fallback_decision'] === 'configuration_missing'))->once();
    Http::assertNothingSent();
    Sleep::assertNeverSlept();
});

test('missing Gemini fallback credentials log the attempted fallback and its configuration failure', function () {
    Log::spy();
    config(['services.gemini.key' => null]);
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([], 502)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.ask'), ['question' => 'Summarize my account'])
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'AI integration is not configured yet.']);

    Log::shouldHaveReceived('info')->with('AI provider fallback attempted', Mockery::on(fn (array $context): bool => $context['to_provider'] === 'gemini'))->once();
    Log::shouldHaveReceived('warning')->with('AI provider request failed', Mockery::on(fn (array $context): bool => $context['provider'] === 'gemini'
        && $context['fallback'] === true
        && $context['upstream_status'] === null
        && $context['failure_reason'] === 'configuration_missing'
        && $context['fallback_decision'] === 'already_attempted'))->once();
    Log::shouldHaveReceived('warning')->with('AI request failed', Mockery::on(fn (array $context): bool => $context['fallback_attempted'] === true
        && $context['primary_failure_reason'] === 'upstream_unavailable'
        && $context['fallback_failure_reason'] === 'configuration_missing'))->once();
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('untrusted provider error fields cannot leak API keys or business prompts into logs', function () {
    $entries = [];
    $capture = function (string $message, array $context = []) use (&$entries): void {
        $entries[] = ['message' => $message, 'context' => $context];
    };
    Log::shouldReceive('warning')->twice()->andReturnUsing($capture);
    Log::shouldReceive('info')->never();
    Log::shouldReceive('error')->never();
    $sensitive = 'groq-test-key gemini-test-key confidential-invoice-amount-98765';
    Http::fake([
        'https://api.groq.com/openai/v1/chat/completions' => Http::response([
            'error' => ['message' => $sensitive, 'type' => $sensitive, 'code' => $sensitive],
        ], 400),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('ai.invoice.generate'), ['prompt' => 'Draft confidential-invoice-amount-98765'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'AI provider configuration needs attention. Please contact support.']);

    expect($entries)->toHaveCount(2);
    expect(json_encode($entries))->not->toContain('groq-test-key', 'gemini-test-key', 'confidential-invoice-amount-98765');
    expect($entries[0]['context']['upstream_error_code'])->toBeNull();
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    $this->assertDatabaseCount('pending_actions', 0);
    $this->assertDatabaseCount('invoices', 0);
});
