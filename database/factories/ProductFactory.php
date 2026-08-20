<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 0.2, 20);

        return [
            'category_id' => Category::factory(),
            'name' => fake()->words(2, true),
            'sku' => 'SKU-'.fake()->unique()->numerify('######'),
            'barcode' => fake()->unique()->numerify('############'),
            'description' => null,
            'cost_price' => $cost,
            'sell_price' => round($cost * 2.2, 2),
            'tax_rate' => 10.00,
            'unit' => 'pcs',
            'track_stock' => true,
            'is_active' => true,
        ];
    }

    /** Explicitly zero-rated. An explicit 0.00 is never overridden by the default. */
    public function taxFree(): static
    {
        return $this->state(fn () => ['tax_rate' => '0.00']);
    }

    /** No rate of its own, so it inherits default_tax_rate from settings. */
    public function inheritsTax(): static
    {
        return $this->state(fn () => ['tax_rate' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
