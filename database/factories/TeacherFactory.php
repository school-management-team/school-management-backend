<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'specialization' => fake()->jobTitle(),
            'cv' => fake()->paragraph(),
            'legal_document_path' => 'doc.pdf',
        ];
    }
}