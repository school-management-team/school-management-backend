<?php

namespace Database\Seeders;

use App\Models\GradeSubmission;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class GradeSubmissionsSeeder extends Seeder
{
    public function run(): void
    {
        if (TeacherAssignment::count() === 0) {
            $this->command->warn('لا يوجد تعيينات معلمين. قم بتشغيل TeacherAssignmentsSeeder أولاً.');
            return;
        }

        $statuses = ['submitted', 'approved', 'rejected'];

        foreach (TeacherAssignment::all() as $assignment) {
            for ($semester = 1; $semester <= 2; $semester++) {
                GradeSubmission::firstOrCreate([
                    'teacher_assignment_id' => $assignment->id,
                    'semester' => $semester,
                ], [
                    'status' => fake()->randomElement($statuses),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
