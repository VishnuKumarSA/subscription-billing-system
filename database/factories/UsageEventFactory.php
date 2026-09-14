<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UsageEvent>
 *
 * customer_id and merchant_id are independent defaults here - tests that
 * care about the customer/merchant relationship (almost all of them)
 * override both explicitly, e.g.:
 * UsageEvent::factory()->create(['customer_id' => $c->id, 'merchant_id' => $c->merchant_id, ...]).
 */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'merchant_id' => Merchant::factory(),
            'usage_date' => fake()->date(),
            'units' => fake()->numberBetween(1, 100),
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
