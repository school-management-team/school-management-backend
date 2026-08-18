<?php

namespace Database\Factories;

use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

class StageFactory extends Factory
{
    protected $model = Stage::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['primary', 'middle', 'high_scientific', 'high_literary']),
        ];
    }
}
