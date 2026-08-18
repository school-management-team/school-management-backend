<?php

namespace Database\Seeders;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GuardianStudentSeeder extends Seeder
{
    public function run(): void
    {
        $guardians = Guardian::all();
        $students = Student::all();

        foreach ($guardians as $guardian) {
            // كل ولي أمر يربط بـ 1-3 طلاب
            $studentCount = rand(1, 3);
            $selectedStudents = $students->random(min($studentCount, $students->count()));

            foreach ($selectedStudents as $student) {
                DB::table('guardian_student')->insert([
                    'guardian_id' => $guardian->id,
                    'student_id' => $student->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
