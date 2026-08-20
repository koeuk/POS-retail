<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Register> */
class RegisterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => 'Register '.fake()->numberBetween(1, 9),
            'is_active' => true,
        ];
    }
}
