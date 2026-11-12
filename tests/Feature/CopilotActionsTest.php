<?php

use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('customer clarification retains Ken and collects a phone without email before explicit idempotent confirmation', function () {
    config(['services.ai.primary_provider' => 'groq', 'services.ai.fallback_provider' => null, 'services.groq.key' => 'test-key']);
    Http::preventStrayRequests();
    $sequence = Http::sequence();
    foreach ([
        ['intent' => 'question', 'message' => 'What would you like to do with Ken?'],
        ['intent' => 'action', 'action' => 'customer.create', 'action_data' => ['name' => 'Ken']],
        ['intent' => 'action', 'action' => 'customer.create', 'action_data' => ['phone' => '+44 20 7946 0958']],
    ] as $reply) {
        $sequence->push(['choices' => [['message' => ['content' => json_encode($reply)]]]]);
    }
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => $sequence]);
    $user = User::factory()->create();
    $thread = (string) Str::uuid();
    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Add Ken.', 'conversation_id' => $thread])->assertOk();
    $this->postJson(route('ai.ask'), ['question' => 'To add him as my customer.', 'conversation_id' => $thread])
        ->assertOk()->assertJsonPath('errors.phone.0', 'The phone field is required.')
        ->assertJsonMissingPath('errors.email')->assertJsonMissingPath('confirmation');
    $this->assertDatabaseCount('customers', 0);
    $proposal = $this->postJson(route('ai.ask'), ['question' => '+44 20 7946 0958', 'conversation_id' => $thread])
        ->assertOk()->assertJsonPath('preview.customer.name', 'Ken')->assertJsonPath('preview.customer.phone', '+44 20 7946 0958')
        ->assertJsonMissingPath('preview.customer.email');
    $this->assertDatabaseCount('customers', 0);
    $token = $proposal->json('confirmation.token');
    $this->postJson(route('ai.actions.confirm'), ['confirmation_token' => $token])->assertOk()->assertJsonPath('already_confirmed', false);
    $this->postJson(route('ai.actions.confirm'), ['confirmation_token' => $token])->assertOk()->assertJsonPath('already_confirmed', true);
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'name' => 'Ken', 'phone' => '+44 20 7946 0958', 'email' => null, 'address' => null]);
    expect(Http::recorded()->last()[0]['messages'][1]['content'])->toContain('Ken', 'phone');
    Http::assertSentCount(3);
});

test('AI customer updates clear optional fields and retain an existing phone after confirmation', function () {
    config(['services.ai.primary_provider' => 'groq', 'services.ai.fallback_provider' => null, 'services.groq.key' => 'test-key']);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Ken', 'phone' => '+1 (202) 555-0123', 'email' => 'ken@example.test', 'address' => 'Old address',
    ]);
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'action', 'action' => 'customer.update',
            'action_data' => ['customer_id' => $customer->id, 'changes' => ['name' => 'Kenneth', 'email' => null, 'address' => null]],
        ])]]],
    ])]);

    $proposal = $this->actingAs($user)->postJson(route('ai.ask'), [
        'question' => 'Rename Ken to Kenneth and remove his email and address.',
    ])->assertOk()->assertJsonPath('preview.changes.name', 'Kenneth')
        ->assertJsonPath('preview.changes.email', null)->assertJsonPath('preview.changes.address', null)
        ->assertJsonMissingPath('preview.changes.phone');
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Ken', 'email' => 'ken@example.test', 'address' => 'Old address']);

    $this->postJson(route('ai.actions.confirm'), ['confirmation_token' => $proposal->json('confirmation.token')])
        ->assertOk()->assertJsonPath('result.customer.name', 'Kenneth');

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Kenneth', 'phone' => '+1 (202) 555-0123', 'email' => null, 'address' => null]);
    Http::assertSentCount(1);
});

test('AI customer updates ask for a phone before preparing to clear an existing phone', function () {
    config(['services.ai.primary_provider' => 'groq', 'services.ai.fallback_provider' => null, 'services.groq.key' => 'test-key']);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Ken', 'phone' => '+254700000001']);
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'action', 'action' => 'customer.update',
            'action_data' => ['customer_id' => $customer->id, 'changes' => ['phone' => null]],
        ])]]],
    ])]);

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Remove the phone number for Ken.'])
        ->assertOk()->assertJsonPath('errors.phone.0', 'The phone field is required.')
        ->assertJsonMissingPath('errors.email')->assertJsonMissingPath('confirmation');

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'phone' => '+254700000001', 'email' => null]);
    $this->assertDatabaseCount('pending_actions', 0);
    Http::assertSentCount(1);
});

test('AI customer update confirmation returns 422 when its pending changes clear the phone', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Ken', 'phone' => '+254700000001']);
    $token = str_repeat('p', 64);
    $pending = PendingAction::factory()->for($user)->create([
        'type' => 'assistant', 'token_hash' => hash('sha256', $token),
        'payload' => ['operation' => 'customer.update', 'data' => ['customer_id' => $customer->id, 'changes' => ['name' => 'Kenneth', 'phone' => null]]],
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($user)->postJson(route('ai.actions.confirm'), ['confirmation_token' => $token])
        ->assertUnprocessable()->assertJsonPath('errors.phone.0', 'The phone field is required.');

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Ken', 'phone' => '+254700000001']);
    $this->assertDatabaseHas('pending_actions', ['id' => $pending->id, 'confirmed_at' => null, 'result' => null]);
});

test('AI updates require a missing legacy phone and retain the requested changes through followup', function () {
    config(['services.ai.primary_provider' => 'groq', 'services.ai.fallback_provider' => null, 'services.groq.key' => 'test-key']);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Ken']);
    $sequence = Http::sequence();
    foreach ([
        ['intent' => 'action', 'action' => 'customer.update', 'action_data' => ['customer_id' => $customer->id, 'changes' => ['name' => 'Kenneth']]],
        ['intent' => 'action', 'action' => 'customer.update', 'action_data' => ['changes' => ['phone' => '+1 202 555 0123']]],
    ] as $reply) {
        $sequence->push(['choices' => [['message' => ['content' => json_encode($reply)]]]]);
    }
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => $sequence]);
    $thread = (string) Str::uuid();

    $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Rename Ken to Kenneth.', 'conversation_id' => $thread])
        ->assertOk()->assertJsonPath('errors.phone.0', 'The phone field is required.')
        ->assertJsonMissingPath('errors.email')->assertJsonMissingPath('confirmation');
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Ken', 'phone' => null, 'email' => null]);
    $proposal = $this->postJson(route('ai.ask'), ['question' => '+1 202 555 0123', 'conversation_id' => $thread])
        ->assertOk()->assertJsonPath('preview.changes.name', 'Kenneth')
        ->assertJsonPath('preview.changes.phone', '+1 202 555 0123')->assertJsonMissingPath('preview.changes.email');
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Ken', 'phone' => null]);

    $this->postJson(route('ai.actions.confirm'), ['confirmation_token' => $proposal->json('confirmation.token')])
        ->assertOk()->assertJsonPath('result.customer.name', 'Kenneth');

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Kenneth', 'phone' => '+1 202 555 0123', 'email' => null, 'address' => null]);
    Http::assertSentCount(2);
});

test('AI customer deletion permits legacy customers without a phone or email after confirmation', function () {
    config(['services.ai.primary_provider' => 'groq', 'services.ai.fallback_provider' => null, 'services.groq.key' => 'test-key']);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Legacy customer']);
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'action', 'action' => 'customer.delete', 'action_data' => ['customer_id' => $customer->id],
        ])]]],
    ])]);

    $proposal = $this->actingAs($user)->postJson(route('ai.ask'), ['question' => 'Delete Legacy customer.'])
        ->assertOk()->assertJsonPath('preview.operation', 'customer.delete');
    $this->assertModelExists($customer);

    $this->postJson(route('ai.actions.confirm'), ['confirmation_token' => $proposal->json('confirmation.token')])
        ->assertOk()->assertJsonPath('result.deleted', true);

    $this->assertModelMissing($customer);
    Http::assertSentCount(1);
});

test('a cancelled proposal cannot be executed and another user cannot cancel it', function () {
    $owner = User::factory()->create();
    $token = str_repeat('c', 64);
    $owner->pendingActions()->create([
        'type' => 'assistant', 'token_hash' => hash('sha256', $token),
        'payload' => ['operation' => 'customer.create', 'data' => ['name' => 'Ken', 'phone' => '+254700000001']],
        'expires_at' => now()->addMinutes(15),
    ]);
    $this->actingAs(User::factory()->create())->postJson(route('ai.actions.cancel'), ['confirmation_token' => $token])->assertNotFound();
    $this->actingAs($owner)->postJson(route('ai.actions.cancel'), ['confirmation_token' => $token])->assertOk();
    $this->postJson(route('ai.actions.confirm'), ['confirmation_token' => $token])->assertNotFound();
    $this->assertDatabaseCount('customers', 0);
});
