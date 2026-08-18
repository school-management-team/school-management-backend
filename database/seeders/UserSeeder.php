<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Supervisor;
use App\Models\Section;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. إنشاء Admin
        User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'user_name' => 'System Admin',
                'email' => 'admin@test.com',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'phone' => '0900000000',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // 2. إنشاء Supervisor
        $supervisorUser = User::updateOrCreate(
            ['email' => 'supervisor@test.com'],
            [
                'user_name' => 'Supervisor User',
                'email' => 'supervisor@test.com',
                'password' => Hash::make('password123'),
                'role' => 'supervisor',
                'phone' => '0911111111',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        Supervisor::updateOrCreate(
            ['user_id' => $supervisorUser->id],
            [
                'user_id' => $supervisorUser->id,
                'educational_qualification' => 'master',
                'specialization' => 'Educational Administration',
                'bio' => 'Experienced educational supervisor with 10 years of experience.',
                'cv_file' => 'supervisor_cv.pdf',
            ]
        );

        // 3. الحصول على أول قسم (Section) موجود
        $section = Section::first();

        if (!$section) {
            $class = SchoolClass::first();
            if (!$class) {
                $this->command->warn('No classes or sections found! Please run SectionsSeeder first.');
                return;
            }
            $section = Section::create([
                'class_id' => $class->id,
                'name' => 'أ',
                'capacity' => 30,
            ]);
        }

        // 4. إنشاء الطلاب
        for ($i = 1; $i <= 6; $i++) {
            $user = User::updateOrCreate(
                ['email' => "student{$i}@test.com"],
                [
                    'user_name' => "Student {$i}",
                    'email' => "student{$i}@test.com",
                    'password' => Hash::make('password123'),
                    'role' => 'student',
                    'phone' => "092000000{$i}",
                    'gender' => 'male',
                    'birth_date' => '2010-01-01',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            Student::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'student_number' => str_pad($i, 5, '0', STR_PAD_LEFT),
                    'father_name' => "Father {$i}",
                    'mother_name' => "Mother {$i}",
                    'enrollment_date' => now()->toDateString(),
                    'class_id' => $section->class_id,
                    'section_id' => $section->id,
                    'user_id' => $user->id,
                ]
            );
        }

        // 5. إنشاء المعلمين
        $teachers = [
            [
                'name' => 'Teacher Math',
                'email' => 'math@test.com',
                'phone' => '0930000001',
                'subject_id' => 1, // تأكد من وجود هذه الـ IDs
                'stage_id' => 1,   // تأكد من وجود هذه الـ IDs
                'cv' => 'math_teacher_cv.pdf',
                'legal_document_path' => 'math_teacher_license.pdf',
            ],
            [
                'name' => 'Teacher Physics',
                'email' => 'physics@test.com',
                'phone' => '0930000002',
                'subject_id' => 2,
                'stage_id' => 1,
                'cv' => 'physics_teacher_cv.pdf',
                'legal_document_path' => 'physics_teacher_license.pdf',
            ],
            [
                'name' => 'Teacher Arabic',
                'email' => 'arabic@test.com',
                'phone' => '0930000003',
                'subject_id' => 3,
                'stage_id' => 1,
                'cv' => 'arabic_teacher_cv.pdf',
                'legal_document_path' => 'arabic_teacher_license.pdf',
            ],
        ];

        foreach ($teachers as $teacherData) {
            $user = User::updateOrCreate(
                ['email' => $teacherData['email']],
                [
                    'user_name' => $teacherData['name'],
                    'email' => $teacherData['email'],
                    'password' => Hash::make('password123'),
                    'role' => 'teacher',
                    'phone' => $teacherData['phone'],
                    'gender' => 'male',
                    'birth_date' => '1985-01-01',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            Teacher::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'user_id' => $user->id,
                    'subject_id' => $teacherData['subject_id'],
                    'stage_id' => $teacherData['stage_id'],
                    'cv' => $teacherData['cv'], // ✅ مطلوب
                    'legal_document_path' => $teacherData['legal_document_path'], // ✅ مطلوب
                ]
            );
        }
    }
}
