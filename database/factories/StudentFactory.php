<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_number' => null, // يتولّد لاحقًا بالسيدر
            'father_name' => fake()->name('male'),
            'mother_name' => fake()->name('female'),
            'class_id' => SchoolClass::inRandomOrder()->first()?->id,
            'enrollment_date' => now(),
        ];
    }
}