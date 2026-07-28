<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectsSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            ['name' => 'الرياضيات', 'passing_grade' => 50],
            ['name' => 'اللغة العربية', 'passing_grade' => 50],
            ['name' => 'اللغة الإنجليزية', 'passing_grade' => 50],
            ['name' => 'العلوم', 'passing_grade' => 50],
        ];

        foreach ($subjects as $subject) {
            Subject::firstOrCreate(['name' => $subject['name']], $subject);
        }
    }
}