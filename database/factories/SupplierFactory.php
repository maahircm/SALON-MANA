<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->unique()->company(), 'contact_person' => fake()->name(), 'phone' => '+25261'.fake()->numerify('#######'), 'email' => fake()->unique()->safeEmail(), 'is_active' => true];
    }
}
