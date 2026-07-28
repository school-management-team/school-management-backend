<?php

namespace Database\Seeders;

use App\Models\Stage;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class StagesAndClassesSeeder extends Seeder
{
    public function run(): void
    {
        $primary = Stage::firstOrCreate(['name' => 'primary']);
        $middle  = Stage::firstOrCreate(['name' => 'middle']);
        $high    = Stage::firstOrCreate(['name' => 'high']);

        $classes = [
            1 => ['name' => 'الأول الابتدائي', 'stage_id' => $primary->id],
            2 => ['name' => 'الثاني الابتدائي', 'stage_id' => $primary->id],
            3 => ['name' => 'الثالث الابتدائي', 'stage_id' => $primary->id],
            4 => ['name' => 'الرابع الابتدائي', 'stage_id' => $primary->id],
            5 => ['name' => 'الخامس الابتدائي', 'stage_id' => $primary->id],
            6 => ['name' => 'السادس الابتدائي', 'stage_id' => $primary->id],
            7 => ['name' => 'الأول المتوسط',   'stage_id' => $middle->id],
            8 => ['name' => 'الثاني المتوسط',  'stage_id' => $middle->id],
            9 => ['name' => 'الثالث المتوسط',  'stage_id' => $middle->id],
            10 => ['name' => 'الأول الثانوي',   'stage_id' => $high->id],
            11 => ['name' => 'الثاني الثانوي',  'stage_id' => $high->id],
            12 => ['name' => 'الثالث الثانوي',  'stage_id' => $high->id],
        ];

        foreach ($classes as $gradeOrder => $data) {
            // تغيير من firstOrCreate إلى updateOrCreate
            SchoolClass::updateOrCreate(
                ['grade_order' => $gradeOrder],
                $data
            );
        }
    }
}