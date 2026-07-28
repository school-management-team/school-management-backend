<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Section;
use Illuminate\Database\Seeder;

class SectionsSeeder extends Seeder
{
    public function run(): void
    {
        SchoolClass::all()->each(function ($class) {
            foreach (['أ', 'ب'] as $name) {
                Section::firstOrCreate(['class_id' => $class->id, 'name' => $name]);
            }
        });
    }
}