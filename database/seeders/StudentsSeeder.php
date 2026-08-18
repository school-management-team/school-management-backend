<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\Classes;
use App\Models\Section;
use Illuminate\Database\Seeder;

class StudentsSeeder extends Seeder
{
    public function run(): void
    {
        // التحقق من وجود الفصول والشعب
        if (SchoolClass::count() === 0 || Section::count() === 0) {
            $this->command->warn('لا يوجد فصول أو شعب. قم بتشغيل ClassesSeeder و SectionsSeeder أولاً.');
            return;
        }

        $usedNumbers = [];

        // إنشاء 20 طالب
        for ($i = 0; $i < 20; $i++) {
            $class = SchoolClass::inRandomOrder()->first();
            $section = Section::where('class_id', $class->id)->inRandomOrder()->first();

            // إنشاء رقم طالب فريد مكون من 5 أرقام
            do {
                $studentNumber = str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            } while (in_array($studentNumber, $usedNumbers) || Student::where('student_number', $studentNumber)->exists());

            $usedNumbers[] = $studentNumber;

            $user = User::create([
                'user_name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password123'),
                'role' => 'student',
                'phone' => fake()->unique()->numerify('09########'),
                'gender' => fake()->randomElement(['male', 'female']),
                'birth_date' => fake()->date('Y-m-d', '2015-01-01'),
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Student::create([
                'student_number' => $studentNumber,
                'father_name' => fake()->name('male'),
                'mother_name' => fake()->name('female'),
                'nationality' => fake()->country(),
                'national_id' => fake()->unique()->numerify('##############'),
                'address' => fake()->address(),
                'medical_notes' => fake()->optional(0.3)->sentence(),
                'enrollment_date' => fake()->dateTimeBetween('-2 years', 'now'),
                'user_id' => $user->id,
                'class_id' => $class->id,
                'section_id' => $section ? $section->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
