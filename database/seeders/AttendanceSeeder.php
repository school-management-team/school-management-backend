<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\Section;
use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        if (Student::count() === 0 || Section::count() === 0) {
            $this->command->warn('لا يوجد طلاب أو شعب. قم بتشغيل UserSeeder أولاً.');
            return;
        }

        $this->command->info('بدء إنشاء سجلات الحضور...');

        // ✅ التأكد من وجود مشرف
        $supervisor = Supervisor::first();

        if (!$supervisor) {
            $this->command->warn('لا يوجد مشرفون. جاري إنشاء مشرف افتراضي...');

            // إنشاء مستخدم مشرف
            $user = User::updateOrCreate(
                ['email' => 'supervisor@school.com'],
                [
                    'user_name' => 'School Supervisor',
                    'email' => 'supervisor@school.com',
                    'password' => bcrypt('password123'),
                    'role' => 'supervisor',
                    'phone' => '0912345678',
                    'gender' => 'male',
                    'birth_date' => '1980-01-01',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            // إنشاء سجل مشرف بالأعمدة الصحيحة
            $supervisor = Supervisor::create([
                'user_id' => $user->id,
                'educational_qualification' => 'master',
                'specialization' => 'Educational Supervision',
                'bio' => 'Default supervisor for attendance records.',
                'cv_file' => 'default_cv.pdf',
            ]);

            $this->command->info('تم إنشاء مشرف افتراضي بالـ ID: ' . $supervisor->id);
        }

        $supervisorId = $supervisor->id;
        // ... باقي الكود كما هو ...
    }
}
