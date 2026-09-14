<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->unique()->company(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
