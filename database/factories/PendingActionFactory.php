<?php

namespace Database\Factories;

use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingAction>
 */
class PendingActionFactory extends Factory
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
            'token_hash' => hash('sha256', fake()->uuid()),
            'type' => 'invoice',
            'payload' => [],
            'result' => null,
            'expires_at' => now()->addMinutes(15),
            'confirmed_at' => null,
        ];
    }
}
