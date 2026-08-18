<?php

namespace Database\Seeders;

use App\Models\Stage;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class ClassesSeeder extends Seeder
{
    public function run(): void
    {

        $primary = Stage::where('name', 'primary')->first();
        $middle = Stage::where('name', 'middle')->first();
        $scientific = Stage::where('name', 'high_scientific')->first();
        $literary = Stage::where('name', 'high_literary')->first();

        // ابتدائي: 1-6
        for ($i = 1; $i <= 6; $i++) {
            SchoolClass::UpdateOrCreate(['name' => "الصف $i ابتدائي", 'grade_order' => $i, 'stage_id' => $primary->id]);
        }

        // إعدادي: 7-9
        for ($i = 7; $i <= 9; $i++) {
            SchoolClass::UpdateOrCreate(['name' => "الصف $i إعدادي", 'grade_order' => $i, 'stage_id' => $middle->id]);
        }

        // ثانوي علمي وأدبي: نفس grade_order (10-12)، بس stage_id مختلف
        $highNames = [10 => 'أول ثانوي', 11 => 'ثاني ثانوي', 12 => 'ثالث ثانوي'];

        foreach ($highNames as $order => $name) {
            SchoolClass::UpdateOrCreate(['name' => $name, 'grade_order' => $order, 'stage_id' => $scientific->id]);
            SchoolClass::UpdateOrCreate(['name' => $name, 'grade_order' => $order, 'stage_id' => $literary->id]);
        }
    }
}
