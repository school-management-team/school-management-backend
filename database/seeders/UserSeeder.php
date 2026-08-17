<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {

       User::UpdateORCreate(
        ['email' => 'admin@test.com'],
           [ 'user_name' => 'System Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'phone' => '0900000000',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

    User::UpdateORCreate(
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
                    'class_id' => 16,   
                    'section_id' => null,
                    'user_id' => $user->id,
                ]
            );
        
          $teachers = [
            [
                'name' => 'Teacher Math',
                'email' => 'math@test.com',
                'phone' => '0930000001',
                'subject_id' => 6,
                'stage_id' => 1,
            ],
            [
                'name' => 'Teacher Physics',
                'email' => 'physics@test.com',
                'phone' => '0930000002',
                'subject_id' => 2,
                'stage_id' => 3,
            ],
            [
                'name' => 'Teacher Arabic',
                'email' => 'arabic@test.com',
                'phone' => '0930000003',
                'subject_id' => 5,
                'stage_id' => 1,
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
                    'subject_id' => $teacherData['subject_id'],
                    'stage_id' => $teacherData['stage_id'],
                    'cv' => 'seeded-cv.pdf',
                    'legal_document_path' => 'seeded-license.pdf',
                    'user_id' => $user->id,
                ]
            );
        }
    }
         }
}
