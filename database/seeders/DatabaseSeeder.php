<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StageSeeder::class,
            ClassesSeeder::class,
            SectionsSeeder::class,
            SubjectsSeeder::class,
            StageSubjectSeeder::class,
            UserSeeder::class,
            SupervisorSeeder::class,
            TeacherAssignmentsSeeder::class,
            StudentSectionSeeder::class,
            GuardianSeeder::class,
            StudentFeeSeeder::class,
            WeeklyScheduleSeeder::class,
            GradesSeeder::class,
            AttendanceSeeder::class,
            AnnouncementSeeder::class,
        ]);
    }
}
