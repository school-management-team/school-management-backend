<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class TeacherAssignmentsSeeder extends Seeder
{
    
    public function run(): void
    {
        $teachers = Teacher::with('subject', 'stage', 'user')->get();

        if ($teachers->isEmpty()) {
            $this->command?->warn('ما في معلمين. شغّل UserSeeder أولاً.');
            return;
        }
        
        $classIds = Student::distinct()->pluck('class_id');
        $sections = Section::with('schoolClass')->whereIn('class_id', $classIds)->get();

        if ($sections->isEmpty()) {
            $this->command?->warn('ما في شعب لصفوف فيها طلاب.');
            return;
        }

        $created = 0;
        $skipped = [];

        foreach ($sections as $section) {
            $classStageId = $section->schoolClass ? $section->schoolClass->stage_id : null;

            foreach ($teachers as $teacher) {
                if (!$teacher->subject_id) {
                    continue;
                }

                // المعلم بيدرّس بمرحلته بس
                if ($classStageId && $teacher->stage_id !== $classStageId) {
                    $skipped[$teacher->id] = $teacher;
                    continue;
                }

                TeacherAssignment::firstOrCreate([
                    'teacher_id' => $teacher->id,
                    'subject_id' => $teacher->subject_id,
                    'section_id' => $section->id,
                ]);

                $created++;
            }
        }

        $this->command?->info("تكاليف: {$created}");

        foreach ($skipped as $teacher) {
            $hasAny = TeacherAssignment::where('teacher_id', $teacher->id)->exists();

            if (!$hasAny) {
                $name = $teacher->user ? $teacher->user->user_name : 'معلم #'.$teacher->id;
                $stage = $teacher->stage ? $teacher->stage->name : '-';
                $this->command?->warn("  {$name} ({$stage}) بلا تكاليف — ما في طلاب بمرحلته");
            }
        }
    }
}
