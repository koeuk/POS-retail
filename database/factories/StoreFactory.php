<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Store> */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Store',
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('+855 ## ### ###'),
        ];
    }
}
