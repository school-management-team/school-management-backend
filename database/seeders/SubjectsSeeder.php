<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectsSeeder extends Seeder
{
    public function run(): void
    {
$subjects = ['رياضيات', 'فيزياء', 'كيمياء', 'أحياء', 'اللغة العربية', 'اللغة الإنجليزية', 'التاريخ', 'الجغرافيا'];

        foreach ($subjects as $name) {
            Subject::create(['name' => $name, 'passing_grade' => 50]);
        }
    }
}
