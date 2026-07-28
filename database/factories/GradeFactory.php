<?php

namespace Database\Factories;

use App\Models\Grade;
use App\Models\Student;
use App\Models\TeacherAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class GradeFactory extends Factory
{
    protected $model = Grade::class;

    public function definition(): array
    {
        $types = ['homework', 'quiz', 'exam', 'participation', 'other'];
        $type = fake()->randomElement($types);

        // قيم مختلفة حسب نوع العلامة
        $maxValue = match($type) {
            'homework' => 20,
            'quiz' => 30,
            'exam' => 100,
            'participation' => 10,
            'other' => 50,
            default => 100,
        };

        return [
            'student_id' => Student::inRandomOrder()->first()?->id,
            'teacher_assignment_id' => TeacherAssignment::inRandomOrder()->first()?->id,
            'type' => $type,
            'value' => fake()->numberBetween(0, $maxValue),
            'status' => fake()->randomElement(['draft', 'approved', 'rejected']),
        ];
    }


    public function draft(): self
    {
        return $this->state(fn() => [
            'status' => 'draft',
        ]);
    }


    public function approved(): self
    {
        return $this->state(fn() => [
            'status' => 'approved',
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn() => [
            'status' => 'rejected',
        ]);
    }
}
