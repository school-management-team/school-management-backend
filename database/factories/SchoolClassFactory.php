<?php

namespace Database\Factories;

use App\Models\Classes;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolClassFactory extends Factory
{
    protected $model = Classes::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6']),
            'grade_order' => fake()->numberBetween(1, 12),
            'stage_id' => Stage::factory(),
        ];
    }
}
