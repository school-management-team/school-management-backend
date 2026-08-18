<?php

namespace Database\Seeders;

use App\Models\StudentAssignmentStatus;
use App\Models\Assignment;
use App\Models\Student;
use Illuminate\Database\Seeder;

class StudentAssignmentStatusesSeeder extends Seeder
{
    public function run(): void
    {
        if (Assignment::count() === 0 || Student::count() === 0) {
            $this->command->warn('لا يوجد واجبات أو طلاب. قم بتشغيل AssignmentsSeeder و UserSeeder أولاً.');
            return;
        }

        $assignments = Assignment::take(10)->get();
        $students = Student::take(20)->get();
        $statuses = ['in_progress', 'completed'];

        foreach ($assignments as $assignment) {
            foreach ($students->random(rand(5, 10)) as $student) {
                StudentAssignmentStatus::firstOrCreate([
                    'assignment_id' => $assignment->id,
                    'student_id' => $student->id,
                ], [
                    'status' => fake()->randomElement($statuses),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
