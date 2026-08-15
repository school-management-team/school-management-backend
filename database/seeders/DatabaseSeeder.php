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
            TeacherAssignmentsSeeder::class,
            GradesSeeder::class,
            AttendanceSeeder::class,
        ]);
    }
}
