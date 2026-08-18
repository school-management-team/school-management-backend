<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Section;
use Illuminate\Database\Seeder;

class SectionsSeeder extends Seeder
{
    public function run(): void
    {
        $created = 0;

        foreach (SchoolClass::all() as $class) {
            foreach (['أ', 'ب'] as $name) {
                Section::firstOrCreate(
                    ['class_id' => $class->id, 'name' => $name],
                    ['capacity' => 30]
                );

                $created++;
            }
        }

        $this->command?->info("شعب: {$created}");
    }
}
