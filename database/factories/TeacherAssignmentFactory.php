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
        $section = null;

        if ($teacher) {
            $section = Section::whereHas(
                'schoolClass',
                fn ($query) => $query->where('stage_id', $teacher->stage_id)
            )->inRandomOrder()->first();
        }

        $section = $section ?: Section::inRandomOrder()->first();
        $subject = $teacher && $teacher->subject_id
            ? Subject::find($teacher->subject_id)
            : Subject::inRandomOrder()->first();

        return [
            'teacher_id' => $teacher ? $teacher->id : 1,
            'subject_id' => $subject ? $subject->id : 1,
            'section_id' => $section ? $section->id : 1,
        ];
    }
}
