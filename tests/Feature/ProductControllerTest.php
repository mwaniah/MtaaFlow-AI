<?php

use App\Models\User;

test('product listing returns only the owner inventory and identifies low stock', function () {
    $user = User::factory()->create();
    $lowStockProduct = $user->products()->create([
        'name' => 'Coffee',
        'sku' => 'COFFEE-01',
        'low_stock_threshold' => 3,
    ]);
    $lowStockProduct->quantity = 2;
    $lowStockProduct->save();
    $normalStockProduct = $user->products()->create([
        'name' => 'Tea',
        'sku' => 'TEA-01',
        'low_stock_threshold' => 3,
    ]);
    $normalStockProduct->quantity = 8;
    $normalStockProduct->save();
    User::factory()->create()->products()->create([
        'name' => 'Private product',
        'sku' => 'PRIVATE-01',
    ]);

    $response = $this->actingAs($user)->getJson(route('products.index', ['low_stock_only' => true]));

    $response->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.id', $lowStockProduct->id)
        ->assertJsonPath('products.0.is_low_stock', true)
        ->assertJsonPath('low_stock_count', 1);
});

test('product listing requires authentication', function () {
    $this->getJson(route('products.index'))->assertUnauthorized();
});

test('product creation records starting stock as an inventory adjustment', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('products.store'), [
        'name' => 'Coffee',
        'sku' => 'COFFEE-01',
        'description' => 'Ground coffee',
        'quantity' => 12,
        'low_stock_threshold' => 4,
    ]);

    $response->assertCreated()
        ->assertJsonPath('product.name', 'Coffee')
        ->assertJsonPath('product.quantity', 12)
        ->assertJsonPath('product.low_stock_threshold', 4)
        ->assertJsonPath('product.is_low_stock', false);

    $productId = $response->json('product.id');
    $this->assertDatabaseHas('products', [
        'id' => $productId,
        'user_id' => $user->id,
        'quantity' => 12,
    ]);
    $this->assertDatabaseHas('inventory_adjustments', [
        'product_id' => $productId,
        'quantity_before' => 0,
        'quantity_change' => 12,
        'quantity_after' => 12,
        'notes' => 'Initial stock',
    ]);
});

test('product SKU uniqueness is scoped to the authenticated owner', function () {
    $user = User::factory()->create();
    User::factory()->create()->products()->create([
        'name' => 'Another owner product',
        'sku' => 'SHARED-SKU',
    ]);

    $this->actingAs($user)->postJson(route('products.store'), [
        'name' => 'Owner product',
        'sku' => 'SHARED-SKU',
    ])->assertCreated();

    $response = $this->postJson(route('products.store'), [
        'name' => 'Duplicate product',
        'sku' => 'SHARED-SKU',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('sku');
    $this->assertDatabaseCount('products', 2);
});

test('product details return 404 for another owner', function () {
    $product = User::factory()->create()->products()->create(['name' => 'Private product']);

    $this->actingAs(User::factory()->create())
        ->getJson(route('products.show', $product))
        ->assertNotFound();
});

test('product updates cannot change stock or ownership', function () {
    $user = User::factory()->create();
    $product = $user->products()->create(['name' => 'Coffee']);
    $product->quantity = 7;
    $product->save();
    $otherUser = User::factory()->create();

    $response = $this->actingAs($user)->putJson(route('products.update', $product), [
        'name' => 'Premium coffee',
        'quantity' => 100,
        'user_id' => $otherUser->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('product.name', 'Premium coffee')
        ->assertJsonPath('product.quantity', 7);
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'user_id' => $user->id,
        'quantity' => 7,
    ]);
});

test('product deletion preserves its stock adjustment history', function () {
    $user = User::factory()->create();
    $product = $user->products()->create(['name' => 'Coffee']);
    $adjustment = $product->adjustments()->create([
        'quantity_before' => 0,
        'quantity_change' => 3,
        'quantity_after' => 3,
        'notes' => 'Opening stock',
    ]);

    $this->actingAs($user)
        ->deleteJson(route('products.destroy', $product))
        ->assertNoContent();

    $this->assertSoftDeleted($product);
    $this->assertDatabaseHas('inventory_adjustments', ['id' => $adjustment->id]);
});
