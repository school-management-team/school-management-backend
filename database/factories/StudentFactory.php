<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        $educationLevel = fake()->randomElement([
            'primary',
            'middle',
            'high'
        ]);

        $grade = match ($educationLevel) {
            'primary' => (string) fake()->numberBetween(1, 6),
            'middle' => (string) fake()->numberBetween(7, 9),
            'high' => (string) fake()->numberBetween(10, 12),
        };

        return [
            'student_name' => fake()->name(),
            'father_name' => fake()->name('male'),
            'mother_name' => fake()->name('female'),
            'birth_date' => fake()->dateTimeBetween('-16 years', '-6 years'),
            'gender' => fake()->randomElement(['male', 'female']),
            'education_level' => $educationLevel,
            'grade' => $grade,
            'status' => 'unverified',
            'enrollment_date' => now(),
            'student_number' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
        ]);
    }

    public function grade10(): static
    {
        return $this->state(fn () => [
            'education_level' => 'high',
            'grade' => '10',
        ]);
    }
}
