<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('0## ### ###'),
            'email' => fake()->unique()->safeEmail(),
            'loyalty_points' => 0,
        ];
    }
}
