<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_category_id' => ServiceCategory::factory(),
            'name' => fake()->unique()->words(2, true),
            'duration_minutes' => 60,
            'base_price' => '25.00',
            'tax_rate' => '0.00',
            'is_active' => true,
        ];
    }
}
