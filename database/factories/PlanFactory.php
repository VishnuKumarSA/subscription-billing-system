<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->randomElement(['Starter', 'Growth', 'Pro']),
            'billing_cycle' => 'monthly',
            'base_price_cents' => 300000,
            'included_units' => 50000,
            'overage_rate_micros' => 50000, // ₹0.05 / unit
            'currency' => 'INR',
        ];
    }
}
