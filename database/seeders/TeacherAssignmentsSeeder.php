<?php

namespace Database\Seeders;

use App\Models\Teacher;
use App\Models\Subject;
use App\Models\Section;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class TeacherAssignmentsSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = Subject::all();
        $sections = Section::all();

        Teacher::all()->each(function ($teacher) use ($subjects, $sections) {
            TeacherAssignment::firstOrCreate([
                'teacher_id' => $teacher->id,
                'subject_id' => $subjects->random()->id,
                'section_id' => $sections->random()->id,
            ]);
        });
    }
}
