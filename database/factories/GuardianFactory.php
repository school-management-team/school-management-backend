<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class GuardianFactory extends Factory
{
    public function definition(): array
    {
        return [
            'relationship' => fake()->randomElement(['father', 'mother']),
            'number_of_children' => fake()->numberBetween(1, 5),
            'verification_student_number'=>'10001'
        ];
    }
}
