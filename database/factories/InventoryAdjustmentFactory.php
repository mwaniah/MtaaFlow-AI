<?php

namespace Database\Factories;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAdjustment>
 */
class InventoryAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity_before' => 10,
            'quantity_change' => 5,
            'quantity_after' => 15,
            'notes' => fake()->sentence(),
        ];
    }
}
