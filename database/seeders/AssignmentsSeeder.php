<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class AssignmentsSeeder extends Seeder
{
    public function run(): void
    {
        $assignmentIds = TeacherAssignment::pluck('id');

        if ($assignmentIds->isEmpty()) {
            $this->command->warn('لا يوجد تعيينات معلمين. قم بتشغيل TeacherAssignmentsSeeder أولاً.');
            return;
        }

        Assignment::factory()
            ->count(30)
            ->sequence(fn () => ['teacher_assignment_id' => $assignmentIds->random()])
            ->create();
    }
}
