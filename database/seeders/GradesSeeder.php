<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\Student;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class GradesSeeder extends Seeder
{
    public function run(): void
    {
        // التحقق من وجود بيانات قبل البدء
        if (Student::count() === 0 || TeacherAssignment::count() === 0) {
            $this->command->warn('لا يوجد طلاب أو تعيينات معلمين. قم بتشغيل UserSeeder أولاً.');
            return;
        }

        // تنظيف الجدول قبل البدء
        Grade::truncate();
        
        $this->command->info('بدء إنشاء العلامات...');

        $students = Student::with('schoolClass.sections')->get();
        $assignments = TeacherAssignment::with('subject')->get();

        $createdCount = 0;

        foreach ($students as $student) {
            // اختيار تعيينات تناسب صف الطالب
            $studentAssignments = $assignments->filter(function ($assignment) use ($student) {
                return $assignment->section->class_id === $student->class_id;
            });

            foreach ($studentAssignments->take(3) as $assignment) {
                // علامة مشاركة (participation)
                Grade::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'teacher_assignment_id' => $assignment->id,
                        'type' => 'participation',
                        'semester' => 1,
                    ],
                    [
                        'value' => fake()->numberBetween(10, 20),
                        'status' => 'draft',
                    ]
                );

                // علامة اختبار (quiz)
                Grade::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'teacher_assignment_id' => $assignment->id,
                        'type' => 'quiz',
                        'semester' => 1,
                    ],
                    [
                        'value' => fake()->numberBetween(15, 30),
                        'status' => 'draft',
                    ]
                );

                // علامة امتحان (exam)
                Grade::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'teacher_assignment_id' => $assignment->id,
                        'type' => 'exam',
                        'semester' => 1,
                    ],
                    [
                        'value' => fake()->numberBetween(50, 100),
                        'status' => 'approved',
                    ]
                );

                $createdCount += 3;
            }
        }

        $this->command->info(" تم إنشاء $createdCount علامة بنجاح!");
        $this->command->info(' إجمالي العلامات في قاعدة البيانات: ' . Grade::count());
    }
}