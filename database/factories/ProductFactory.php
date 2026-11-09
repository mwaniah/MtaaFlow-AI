<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'sku' => fake()->unique()->bothify('SKU-####'),
            'description' => fake()->sentence(),
            'quantity' => fake()->numberBetween(0, 100),
            'low_stock_threshold' => 5,
        ];
    }
}
