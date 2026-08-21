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

        /*
         | المعلم بيعلّم بمرحلته حصراً، ولازم مادته تكون من مواد هالمرحلة.
         | فلكل مرحلة كادر من موادها هي — ومنحط أكتر من معلم لنفس المادة
         | حتى إذا غاب واحد يكون في بديل من نفس الاختصاص.
         |
         | (أستاذ التاريخ كان مسجّل بالابتدائي، والابتدائي ما فيه تاريخ —
         |  محلّه الأدبي.)
         */
        $teachers = [
            // ابتدائي
            ['math@test.com', 'أستاذ الرياضيات', '0930000001', 'رياضيات', 'primary'],
            ['math2@test.com', 'أستاذ الرياضيات الثاني', '0930000006', 'رياضيات', 'primary'],
            ['arabic@test.com', 'أستاذ اللغة العربية', '0930000003', 'اللغة العربية', 'primary'],
            ['arabic2@test.com', 'أستاذ اللغة العربية الثاني', '0930000007', 'اللغة العربية', 'primary'],
            ['english@test.com', 'أستاذ اللغة الإنجليزية', '0930000004', 'اللغة الإنجليزية', 'primary'],
            ['bio.primary@test.com', 'أستاذ الأحياء - ابتدائي', '0930000008', 'أحياء', 'primary'],
            ['cs.primary@test.com', 'أستاذ الحاسوب - ابتدائي', '0930000009', 'الحاسوب', 'primary'],

            // إعدادي
            ['math.middle@test.com', 'أستاذ الرياضيات - إعدادي', '0930000010', 'رياضيات', 'middle'],
            ['math.middle2@test.com', 'أستاذ الرياضيات الثاني - إعدادي', '0930000011', 'رياضيات', 'middle'],
            ['arabic.middle@test.com', 'أستاذ اللغة العربية - إعدادي', '0930000012', 'اللغة العربية', 'middle'],
            ['english.middle@test.com', 'أستاذ اللغة الإنجليزية - إعدادي', '0930000013', 'اللغة الإنجليزية', 'middle'],

            // ثانوي علمي
            ['physics@test.com', 'أستاذ الفيزياء', '0930000002', 'فيزياء', 'high_scientific'],
            ['physics2@test.com', 'أستاذ الفيزياء الثاني', '0930000014', 'فيزياء', 'high_scientific'],
            ['math.sci@test.com', 'أستاذ الرياضيات - علمي', '0930000015', 'رياضيات', 'high_scientific'],
            ['chem.sci@test.com', 'أستاذ الكيمياء - علمي', '0930000016', 'كيمياء', 'high_scientific'],
            ['arabic.sci@test.com', 'أستاذ اللغة العربية - علمي', '0930000017', 'اللغة العربية', 'high_scientific'],

            // ثانوي أدبي
            ['history@test.com', 'أستاذ التاريخ', '0930000005', 'التاريخ', 'high_literary'],
            ['history2@test.com', 'أستاذ التاريخ الثاني', '0930000018', 'التاريخ', 'high_literary'],
            ['geo.lit@test.com', 'أستاذ الجغرافيا - أدبي', '0930000019', 'الجغرافيا', 'high_literary'],
            ['arabic.lit@test.com', 'أستاذ اللغة العربية - أدبي', '0930000020', 'اللغة العربية', 'high_literary'],
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
                    // ما منمرّر section_id: إعادة تشغيل السيدر ما لازم
                    // تشيل الطلاب من شعبهم. التوزيع شغل StudentSectionSeeder.
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
