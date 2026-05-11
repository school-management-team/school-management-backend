<?php
// database/factories/StudentFactory.php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        $educationLevel = fake()->randomElement(['primary', 'middle', 'high']);

        $grade = match($educationLevel) {
            'primary' => fake()->numberBetween(1, 6),
            'middle' => fake()->numberBetween(7, 9),
            'high' => fake()->numberBetween(10, 12),
        };

        return [
            'first_name' => fake()->firstName(),
            'father_name' => fake()->firstNameMale(),
            'mother_name' => fake()->firstNameFemale(),
            'last_name' => fake()->lastName(),
            'student_id' => 'STU' . date('Y') . fake()->unique()->numberBetween(1000, 9999),
            'birth_date' => fake()->dateTimeBetween('-16 years', '-6 years'),
            'gender' => fake()->randomElement(['male', 'female']),
            'education_level' => $educationLevel,
            'grade' => $grade,
            'section' => fake()->randomElement(['أ', 'ب', 'ج', 'د']),
            'address' => fake()->address(),
            'guardian_phone' => fake()->phoneNumber(),
            'guardian_email' => fake()->optional()->safeEmail(),
            'guardian_relation' => fake()->randomElement(['father', 'mother']),
            'health_status' => fake()->optional()->sentence(),
            'legal_document_path' => null,
            'bus_id' => null,
            'status' => fake()->randomElement(['unverified','pending', 'active', 'active', 'active']),
            'enrollment_date' => fake()->dateTimeBetween('-3 years', 'now'),
            'wallet_balance' => fake()->randomFloat(2, 0, 500),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    // طالب في الصف العاشر
    public function grade10(): static
    {
        return $this->state(fn (array $attributes) => [
            'education_level' => 'high',
            'grade' => 10,
        ]);
    }
}
