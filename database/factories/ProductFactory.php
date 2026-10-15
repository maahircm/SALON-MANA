<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'name' => fake()->unique()->words(3, true),
            'unit' => 'pc',
            'selling_price' => '20.00',
            'tax_rate' => '0.00',
            'reorder_level' => '0.000',
            'is_sellable' => true,
            'is_active' => true,
        ];
    }

    /** Consumable / back-bar product that is never sold at the till. */
    public function consumable(): static
    {
        return $this->state(fn () => ['is_sellable' => false, 'unit' => 'ml', 'selling_price' => '0.00']);
    }
}
