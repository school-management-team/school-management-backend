<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;

use App\Models\User;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{

    public function run(): void
    {

        $this->makeUser('admin@test.com', 'System Admin', 'admin', '0900000000');

        $this->seedTeachers();
        $this->seedStudents();
    }

    private function seedTeachers(): void
    {

        $teachers = [
            ['math@test.com', 'أستاذ الرياضيات', '0930000001', 'رياضيات', 'primary'],
            ['physics@test.com', 'أستاذ الفيزياء', '0930000002', 'فيزياء', 'high_scientific'],
            ['arabic@test.com', 'أستاذ اللغة العربية', '0930000003', 'اللغة العربية', 'primary'],
            ['english@test.com', 'أستاذ اللغة الإنجليزية', '0930000004', 'اللغة الإنجليزية', 'primary'],
            ['biology@test.com', 'أستاذ الأحياء', '0930000005', 'أحياء', 'primary'],
        ];

        foreach ($teachers as $row) {
            $subject = Subject::where('name', $row[3])->first();
            $stage = Stage::where('name', $row[4])->first();

            if (!$subject || !$stage) {
                $this->command?->warn("تخطّينا {$row[0]}: المادة أو المرحلة مش موجودة");
                continue;
            }

            $user = $this->makeUser($row[0], $row[1], 'teacher', $row[2], '1985-01-01');

            Teacher::updateOrCreate(
                ['user_id' => $user->id],

                [
                    'subject_id' => $subject->id,
                    'stage_id' => $stage->id,
                    'cv' => 'seeded-cv.pdf',
                    'legal_document_path' => 'seeded-license.pdf',
                ]
            );
        }

        $this->command?->info('معلمين: '.Teacher::count());
    }

    private function seedStudents(): void
    {

        $class = SchoolClass::where('grade_order', 1)->first();

        if (!$class) {
            $this->command?->warn('ما في صفوف. شغّل ClassesSeeder أولاً.');
            return;
        }

        for ($i = 1; $i <= 12; $i++) {
            $user = $this->makeUser(
                "student{$i}@test.com",
                "طالب {$i}",
                'student',
                '092000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                '2015-01-01'
            );

            Student::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'student_number' => str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'father_name' => "أبو طالب {$i}",
                    'mother_name' => "أم طالب {$i}",
                    'enrollment_date' => now()->toDateString(),

                    'class_id' => $class->id,
                    'section_id' => null,

                ]
            );
        }

        $this->command?->info('طلاب: '.Student::count().' (بالصف: '.$class->name.')');
    }

    private function makeUser(string $email, string $name, string $role, string $phone, string $birthDate = '1990-01-01'): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'user_name' => $name,
                'password' => Hash::make('password123'),
                'role' => $role,
                'phone' => $phone,
                'gender' => 'male',
                'birth_date' => $birthDate,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );
    }
}
