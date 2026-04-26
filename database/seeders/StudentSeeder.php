<?php
// database/seeders/StudentSeeder.php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        // إنشاء 10 طلاب مفعلين
        Student::factory()
            ->count(10)
            ->active()
            ->create()
            ->each(function ($student) {
                User::factory()
                    ->student()
                    ->active()
                    ->create([
                        'student_id' => $student->id,
                        'email' => 'student' . $student->id . '@school.com',
                    ]);
            });

        // إنشاء 4 طلاب معلقين (بانتظار الموافقة)
        Student::factory()
            ->count(4)
            ->pending()
            ->create()
            ->each(function ($student) {
                User::factory()
                    ->student()
                    ->pending()
                    ->create([
                        'student_id' => $student->id,
                        'email' => 'pending.student' . $student->id . '@school.com',
                    ]);
            });

        // إنشاء 3 طلاب في الصف العاشر
        Student::factory()
            ->count(3)
            ->grade10()
            ->active()
            ->create()
            ->each(function ($student) {
                User::factory()
                    ->student()
                    ->active()
                    ->create([
                        'student_id' => $student->id,
                        'email' => 'grade10.student' . $student->id . '@school.com',
                    ]);
            });

        $this->command->info(' تم إنشاء 17 طالب (10 مفعلين، 4 معلقين، 3 صف عاشر)');
    }
}