<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\TeacherAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    public function definition(): array
    {
        return [
            'teacher_assignment_id' => TeacherAssignment::factory(),
            'title' => fake()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'due_date' => fake()->dateTimeBetween('now', '+1 month'),
            'max_grade' => fake()->numberBetween(50, 100),
            'attachment_path' => fake()->optional(0.3)->filePath(),
            'attachment_link' => fake()->optional(0.2)->url(),
        ];
    }
}
