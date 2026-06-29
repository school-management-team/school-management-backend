<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'education_level' => fake()->randomElement(['primary', 'middle', 'high']),
            'school_class' => fake()->numberBetween(1, 12),
            'specialization' => fake()->jobTitle(),
            'cv' => fake()->paragraph(),
            'legal_document_path' => 'doc.pdf',
        ];
    }
}
