<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StagesAndClassesSeeder::class,
            SectionsSeeder::class,
            SubjectsSeeder::class,
            UserSeeder::class,
            TeacherAssignmentsSeeder::class,
            GradesSeeder::class,
            AttendanceSeeder::class,
        ]);
    }
}
