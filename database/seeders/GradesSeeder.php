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

        $this->command->info('بدء إنشاء العلامات...');


        $this->command->info('إنشاء 30 علامة مسودة...');
        Grade::factory()
            ->count(30)
            ->draft()
            ->create();


        $this->command->info('إنشاء 20 علامة مقبولة...');
        Grade::factory()
            ->count(20)
            ->approved()
            ->create();


        $this->command->info('إنشاء 15 علامة مرفوضة...');
        Grade::factory()
            ->count(15)
            ->rejected()
            ->create();


        $this->command->info('إنشاء علامات لكل طالب في كل مادة...');

        $students = Student::with('schoolClass.sections')->get();
        $assignments = TeacherAssignment::with('subject')->get();

        foreach ($students as $student) {
            // اختيار تعيينات تناسب صف الطالب
            $studentAssignments = $assignments->filter(function ($assignment) use ($student) {
                return $assignment->section->class_id === $student->class_id;
            });

            foreach ($studentAssignments->take(3) as $assignment) {
                // علامة واجب
                Grade::factory()
                    ->draft()
                    ->state([
                        'student_id' => $student->id,
                        'teacher_assignment_id' => $assignment->id,
                        'type' => 'homework',
                        'value' => fake()->numberBetween(10, 20),
                    ])->create();

                // علامة اختبار
                Grade::factory()
                    ->draft()
                    ->state([
                        'student_id' => $student->id,
                        'teacher_assignment_id' => $assignment->id,
                        'type' => 'quiz',
                        'value' => fake()->numberBetween(15, 30),
                    ])->create();

                // علامة امتحان
                Grade::factory()
                    ->approved()
                    ->state([
                        'student_id' => $student->id,
                        'teacher_assignment_id' => $assignment->id,
                        'type' => 'exam',
                        'value' => fake()->numberBetween(50, 100),
                    ])->create();
            }
        }

        $this->command->info('تم إنشاء العلامات بنجاح!');
        $this->command->info('إجمالي العلامات: ' . Grade::count());
    }
}
