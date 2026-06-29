<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_number' => null, // يتم توليده من النظام
            'father_name' => fake()->name('male'),
            'mother_name' => fake()->name('female'),
            'education_level' => fake()->randomElement(['primary', 'middle', 'high']),
            'school_class' => fake()->numberBetween(1, 12),
            'enrollment_date' => now(),
        ];
    }
}
