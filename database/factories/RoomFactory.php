<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Chair '.fake()->unique()->numberBetween(1, 9999), 'type' => 'chair', 'is_active' => true];
    }
}
