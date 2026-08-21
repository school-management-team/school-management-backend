<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class GradesSeeder extends Seeder
{
   
    public function run(): void
    {
        if (Student::whereNotNull('section_id')->count() === 0 || TeacherAssignment::count() === 0) {
            $this->command?->warn('لا يوجد طلاب موزّعين على شعب أو تعيينات معلمين. شغّل UserSeeder و StudentSectionSeeder أولاً.');
            return;
        }

        GradeSubmission::query()->delete();
        Grade::query()->delete();

        $books = [];

        foreach (TeacherAssignment::all() as $assignment) {
            $key = $assignment->section_id.'-'.$assignment->subject_id;

            if (!isset($books[$key])) {
                $books[$key] = $assignment;
            }
        }

        $grades = 0;
        $submissions = 0;
        $index = 0;

        foreach ($books as $assignment) {
            $students = Student::where('section_id', $assignment->section_id)->get();

            if ($students->isEmpty()) {
                continue;
            }


            $partial = $index % 4 === 0;
            $status = $index % 3 === 0 ? 'submitted' : 'approved';
            $index++;

            foreach ($students as $student) {
                foreach (['participation' => [50, 100], 'quiz' => [40, 100], 'exam' => [35, 100]] as $type => $range) {
                    if ($partial && $type === 'exam') {
                        continue;
                    }

                    Grade::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'subject_id' => $assignment->subject_id,
                            'type' => $type,
                            'semester' => 1,
                        ],
                        [
                            'section_id' => $assignment->section_id,
                            'teacher_assignment_id' => $assignment->id,
                            'value' => fake()->numberBetween(...$range),
                            'status' => 'draft',
                        ]
                    );

                    $grades++;
                }
            }

            if ($partial) {
                continue;
            }


            GradeSubmission::updateOrCreate(
                [
                    'section_id' => $assignment->section_id,
                    'subject_id' => $assignment->subject_id,
                    'semester' => 1,
                ],
                [
                    'teacher_assignment_id' => $assignment->id,
                    'status' => $status,
                ]
            );

            $submissions++;
        }

        $this->command?->info("تم إنشاء {$grades} علامة ضمن {$submissions} دفتر مكتمل.");
    }
}
