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

                /*
                 | والمادة لازم تكون مقررة بهالمرحلة (جدول stage_subject).
                 | بدون هالفحص بينخلق تكليف زي "التاريخ لصف ابتدائي" —
                 | والتاريخ مقرر بالأدبي بس.
                 |
                 | منفحص من $taught المحمّلة فوق بدل استعلام لكل معلم بكل
                 | شعبة — هون جوّا لوبين متداخلين، فالفرق مئات الاستعلامات.
                 */
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
                $subject = $teacher->subject ? $teacher->subject->name : '-';

                /*
                 | سببين مختلفين تماماً وكانوا برسالة وحدة. التمييز بينهن
                 | بيوفّر وقت: مشكلة بتوزيع الطلاب، ولا مشكلة بـ stage_subject؟
                 */
                $subjectTaught = $teacher->subject_id
                    && isset($taught[$teacher->stage_id.'-'.$teacher->subject_id]);

                $reason = $subjectTaught
                    ? "لا يوجد صف فيه طلاب بمرحلة {$stage}"
                    : "مادة {$subject} غير مقررة في مرحلة {$stage}";

                $this->command?->warn("  {$name} بلا تكاليف — {$reason}");
            }
        }
    }
}
