<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id'   => (string) Str::uuid(),
            'name' => fake()->company() . ' склад',
        ];
    }
}
