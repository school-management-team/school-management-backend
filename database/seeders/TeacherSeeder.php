<?php
// database/seeders/TeacherSeeder.php

namespace Database\Seeders;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeacherSeeder extends Seeder
{
    public function run(): void
    {
        // إنشاء 5 معلمين مفعلين
        Teacher::factory()
            ->count(5)
            ->active()
            ->create()
            ->each(function ($teacher) {
                User::factory()
                    ->teacher()
                    ->active()
                    ->create([
                        'teacher_id' => $teacher->id,
                        'email' => 'teacher' . $teacher->id . '@school.com',
                    ]);
            });

        // إنشاء 3 معلمين معلقين (بانتظار الموافقة)
        Teacher::factory()
            ->count(3)
            ->pending()
            ->create()
            ->each(function ($teacher) {
                User::factory()
                    ->teacher()
                    ->pending()
                    ->create([
                        'teacher_id' => $teacher->id,
                        'email' => 'pending.teacher' . $teacher->id . '@school.com',
                    ]);
            });

        $this->command->info(' تم إنشاء 8 معلمين (5 مفعلين، 3 معلقين)');
    }
}