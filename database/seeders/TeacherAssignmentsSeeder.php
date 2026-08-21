<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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

        $taught = [];

        foreach (DB::table('stage_subject')->get() as $row) {
            $taught[$row->stage_id.'-'.$row->subject_id] = true;
        }

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

                if ($classStageId && !isset($taught[$classStageId.'-'.$teacher->subject_id])) {
                    $skipped[$teacher->id] = $teacher;
                    continue;
                }

                $assignment = TeacherAssignment::firstOrCreate([
                    'teacher_id' => $teacher->id,
                    'subject_id' => $teacher->subject_id,
                    'section_id' => $section->id,
                ]);

                if ($assignment->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $this->command?->info("تكاليف: {$created}");

        foreach ($skipped as $teacher) {
            $hasAny = TeacherAssignment::where('teacher_id', $teacher->id)->exists();

            if (!$hasAny) {
                $name = $teacher->user ? $teacher->user->user_name : 'معلم #'.$teacher->id;
                $stage = $teacher->stage ? $teacher->stage->name : '-';

                $subjectTaught = $teacher->subject_id
                    && isset($taught[$teacher->stage_id.'-'.$teacher->subject_id]);

                $reason = $subjectTaught
                    ? 'ما في طلاب بمرحلته'
                    : 'مادته ('.($teacher->subject ? $teacher->subject->name : '-').') مش مقررة بمرحلته';

                $this->command?->warn("  {$name} ({$stage}) بلا تكاليف — {$reason}");
            }
        }
    }
}
