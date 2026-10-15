<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+25261'.fake()->unique()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'status' => 'active',
            'registered_on' => now()->toDateString(),
        ];
    }
}
