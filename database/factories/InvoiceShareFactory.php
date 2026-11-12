<?php

namespace Database\Factories;

use App\Models\InvoiceShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceShare>
 */
class InvoiceShareFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => function (): int {
                $user = User::factory()->create();
                $customer = $user->customers()->create(['name' => fake()->name()]);

                return $user->invoices()->create([
                    'customer_id' => $customer->id,
                    'invoice_number' => 'INV-'.strtoupper(fake()->unique()->bothify('########')),
                    'currency' => 'USD',
                    'subtotal' => '1.00',
                    'total' => '1.00',
                    'status' => 'approved',
                    'issue_date' => today(),
                ])->id;
            },
            'token_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addDays(30),
            'revoked_at' => null,
            'emailed_at' => null,
            'email_sent_to' => null,
        ];
    }
}
