<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class AssignmentsSeeder extends Seeder
{
    public function run(): void
    {
        if (TeacherAssignment::count() === 0) {
            $this->command->warn('لا يوجد تعيينات معلمين. قم بتشغيل TeacherAssignmentsSeeder أولاً.');
            return;
        }

        Assignment::factory()->count(30)->create();
    }
}
