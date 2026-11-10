<?php

use App\Models\User;

test('stock adjustments persist exact before and after quantities', function () {
    $user = User::factory()->create();
    $product = $user->products()->create(['name' => 'Coffee']);
    $product->quantity = 10;
    $product->save();

    $addition = $this->actingAs($user)->postJson(route('products.adjustments.store', $product), [
        'quantity_change' => 4,
        'notes' => 'Received shipment',
    ]);

    $addition->assertCreated()
        ->assertJsonPath('adjustment.quantity_before', 10)
        ->assertJsonPath('adjustment.quantity_change', 4)
        ->assertJsonPath('adjustment.quantity_after', 14)
        ->assertJsonPath('product.quantity', 14);

    $removal = $this->postJson(route('products.adjustments.store', $product), [
        'quantity_change' => -3,
        'notes' => 'Damaged units',
    ]);

    $removal->assertCreated()
        ->assertJsonPath('adjustment.quantity_before', 14)
        ->assertJsonPath('adjustment.quantity_change', -3)
        ->assertJsonPath('adjustment.quantity_after', 11)
        ->assertJsonPath('product.quantity', 11);

    $this->assertDatabaseCount('inventory_adjustments', 2);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 11]);

    $history = $this->getJson(route('products.adjustments.index', $product));

    $history->assertOk()
        ->assertJsonPath('product_id', $product->id)
        ->assertJsonCount(2, 'adjustments.data')
        ->assertJsonPath('adjustments.data.0.quantity_after', 11)
        ->assertJsonPath('adjustments.data.1.quantity_after', 14);
});

test('stock adjustment returns 422 without changing stock when it would go below zero', function () {
    $user = User::factory()->create();
    $product = $user->products()->create(['name' => 'Coffee']);
    $product->quantity = 2;
    $product->save();

    $response = $this->actingAs($user)->postJson(route('products.adjustments.store', $product), [
        'quantity_change' => -3,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('quantity_change');
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 2]);
    $this->assertDatabaseCount('inventory_adjustments', 0);
});

test('stock adjustment returns 422 when the result exceeds the supported quantity', function () {
    $user = User::factory()->create();
    $product = $user->products()->create(['name' => 'Coffee']);
    $product->quantity = 2_147_483_647;
    $product->save();

    $response = $this->actingAs($user)->postJson(route('products.adjustments.store', $product), [
        'quantity_change' => 1,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('quantity_change');
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 2_147_483_647]);
    $this->assertDatabaseCount('inventory_adjustments', 0);
});

test('stock adjustment rejects a zero quantity change', function () {
    $user = User::factory()->create();
    $product = $user->products()->create(['name' => 'Coffee']);

    $this->actingAs($user)
        ->postJson(route('products.adjustments.store', $product), ['quantity_change' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quantity_change');

    $this->assertDatabaseCount('inventory_adjustments', 0);
});

test('stock adjustment endpoints return 404 for another owner', function () {
    $product = User::factory()->create()->products()->create(['name' => 'Private product']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('products.adjustments.index', $product))
        ->assertNotFound();

    $this->postJson(route('products.adjustments.store', $product), ['quantity_change' => 1])
        ->assertNotFound();

    $this->assertDatabaseCount('inventory_adjustments', 0);
});

test('stock adjustment endpoints require authentication', function () {
    $product = User::factory()->create()->products()->create(['name' => 'Coffee']);

    $this->postJson(route('products.adjustments.store', $product), ['quantity_change' => 1])
        ->assertUnauthorized();
});
