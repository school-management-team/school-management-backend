<?php

namespace Database\Factories;

use App\Models\TeacherAssignment;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherAssignmentFactory extends Factory
{
    protected $model = TeacherAssignment::class;

    public function definition(): array
    {
        $teacher = Teacher::inRandomOrder()->first();
        $subject = Subject::inRandomOrder()->first();
        $section = Section::inRandomOrder()->first();

        return [
            'teacher_id' => $teacher ? $teacher->id : 1,
            'subject_id' => $subject ? $subject->id : 1,
            'section_id' => $section ? $section->id : 1,
        ];
    }
}
