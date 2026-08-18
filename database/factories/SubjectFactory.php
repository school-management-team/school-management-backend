<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Mathematics', 'Physics', 'Chemistry', 'Biology', 'Arabic', 'English']),
            'passing_grade' => fake()->numberBetween(40, 60),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
