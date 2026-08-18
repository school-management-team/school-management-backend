<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectsSeeder extends Seeder
{
    public function run(): void
    { 
 $subjects = [
            ['name' => 'رياضيات', 'passing_grade' => 50, 'description' => null],
            ['name' => 'فيزياء', 'passing_grade' => 50, 'description' => null],
            ['name' => 'كيمياء', 'passing_grade' => 50, 'description' => null],
            ['name' => 'أحياء', 'passing_grade' => 50, 'description' => null],
            ['name' => 'اللغة العربية', 'passing_grade' => 50, 'description' => null],
            ['name' => 'اللغة الإنجليزية', 'passing_grade' => 50, 'description' => null],
            ['name' => 'التاريخ', 'passing_grade' => 50, 'description' => null],
            ['name' => 'الجغرافيا', 'passing_grade' => 50, 'description' => null],
        ];
        foreach ($subjects as $subject ) {
            Subject::UpdateOrCreate(
                 ['name' => $subject['name']],
                 ['name' => $subject['name'],
                    'passing_grade' => $subject['passing_grade'],
                    'description' => $subject['description'],
            ]);
        }
    }
}
