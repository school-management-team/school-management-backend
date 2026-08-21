<?php

namespace Database\Factories;

use App\Models\Grade;
use App\Models\Student;
use App\Models\TeacherAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class GradeFactory extends Factory
{protected $model = Grade::class;

    public function definition(): array
    {
        // كل مكوّن من 100 — الوزن بينطبّق وقت حساب المحصّلة، مش هون
        $type = fake()->randomElement(array_keys(config('school.grade_components')));
        $assignment = TeacherAssignment::inRandomOrder()->first();

        $student = $assignment
            ? Student::where('section_id', $assignment->section_id)->inRandomOrder()->first()
            : Student::inRandomOrder()->first();

        return [
            'student_id' => $student?->id,
            'teacher_assignment_id' => $assignment?->id,
            'subject_id' => $assignment?->subject_id,
            'section_id' => $assignment?->section_id,
            'type' => $type,
            'value' => fake()->numberBetween(0, 100),
            'status' => fake()->randomElement(['draft', 'approved', 'rejected']),
            'semester' => fake()->numberBetween(1, 2),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'semester' => 1,
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'semester' => 1,
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'semester' => 1,
        ]);
    }
}
