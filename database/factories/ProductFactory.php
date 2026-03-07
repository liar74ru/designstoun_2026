<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'            => fake()->words(3, true),
            'sku'             => strtoupper(Str::random(8)),
            'price'           => fake()->randomFloat(2, 100, 10000),
            'prod_cost_coeff' => fake()->randomFloat(2, 0.5, 3.0),
            'moysklad_id'     => (string) Str::uuid(),
            'is_active'       => true,
        ];
    }
}
