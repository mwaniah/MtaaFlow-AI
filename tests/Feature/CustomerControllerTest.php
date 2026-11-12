<?php

use App\Models\User;

test('customers can be created without email or address using international phone numbers', function (string $phone) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('customers.store'), ['name' => 'Customer', 'phone' => $phone])
        ->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customers', [
        'user_id' => $user->id, 'name' => 'Customer', 'phone' => $phone, 'email' => null, 'address' => null,
    ]);
})->with(['Kenya' => '+254712345678', 'United Kingdom' => '+44 20 7946 0958', 'United States' => '+1 (415) 555-2671']);

test('empty optional customer fields are saved as null', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Customer', 'phone' => '+442079460958', 'email' => '', 'address' => '',
    ])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'email' => null, 'address' => null]);
});

test('customer creation rejects a missing or blank phone without requiring email', function (array $phone) {
    $this->actingAs(User::factory()->create())->postJson(route('customers.store'), ['name' => 'Customer', ...$phone])
        ->assertUnprocessable()->assertJsonPath('errors.phone.0', 'The phone field is required.')
        ->assertJsonMissingPath('errors.email');

    $this->assertDatabaseCount('customers', 0);
})->with(['omitted' => [[]], 'null' => [['phone' => null]], 'empty' => [['phone' => '']], 'whitespace' => [['phone' => '   ']]]);

test('customer creation still requires a name', function () {
    $this->actingAs(User::factory()->create())->postJson(route('customers.store'), ['phone' => '+14155552671'])
        ->assertUnprocessable()->assertJsonPath('errors.name.0', 'The name field is required.');

    $this->assertDatabaseCount('customers', 0);
});

test('an optional customer email must be valid when supplied', function () {
    $this->actingAs(User::factory()->create())->postJson(route('customers.store'), [
        'name' => 'Customer', 'phone' => '+14155552671', 'email' => 'invalid-email',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('customers', 0);
});

test('customer updates can change name and international phone and clear optional fields', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Original', 'phone' => '+254712345678', 'email' => 'original@example.test', 'address' => 'Original address',
    ]);
    $untouched = $user->customers()->create(['name' => 'Untouched', 'email' => 'keep@example.test']);
    $before = $untouched->refresh()->getAttributes();

    $this->actingAs($user)->put(route('customers.update', $customer), [
        'name' => 'Updated', 'phone' => '+44 20 7946 0958', 'email' => null, 'address' => null,
    ])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id, 'name' => 'Updated', 'phone' => '+44 20 7946 0958', 'email' => null, 'address' => null,
    ]);
    expect($untouched->fresh()->getAttributes())->toBe($before);
});

test('partial customer updates preserve omitted contact details', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create([
        'name' => 'Original', 'phone' => '+14155552671', 'email' => 'keep@example.test', 'address' => 'Keep address',
    ]);

    $this->actingAs($user)->put(route('customers.update', $customer), ['name' => 'Updated'])
        ->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id, 'name' => 'Updated', 'phone' => '+14155552671', 'email' => 'keep@example.test', 'address' => 'Keep address',
    ]);
});

test('customer updates cannot clear required contact fields', function (string $field) {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Original', 'phone' => '+14155552671']);
    $before = $customer->refresh()->getAttributes();

    $this->actingAs($user)->putJson(route('customers.update', $customer), [$field => null])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($customer->fresh()->getAttributes())->toBe($before);
})->with(['name', 'phone']);

test('updating a legacy customer without a phone asks for it without changing existing data', function () {
    $user = User::factory()->create();
    $customer = $user->customers()->create(['name' => 'Legacy', 'email' => 'keep@example.test']);
    $before = $customer->refresh()->getAttributes();

    $this->actingAs($user)->putJson(route('customers.update', $customer), ['name' => 'Changed'])
        ->assertUnprocessable()->assertJsonPath('errors.phone.0', 'The phone field is required.');

    expect($customer->fresh()->getAttributes())->toBe($before);

    $this->put(route('customers.update', $customer), ['phone' => '+442079460958'])
        ->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id, 'name' => 'Legacy', 'phone' => '+442079460958', 'email' => 'keep@example.test', 'address' => null,
    ]);
});
