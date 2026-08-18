<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\Student;
use Illuminate\Database\Seeder;

class StudentSectionSeeder extends Seeder
{

    public function run(): void
    {
        $assigned = 0;
        $byClass = Student::whereNull('section_id')->get()->groupBy('class_id');

        foreach ($byClass as $classId => $students) {
            $sections = Section::where('class_id', $classId)->orderBy('id')->get();

            if ($sections->isEmpty()) {
                $this->command?->warn("الصف {$classId} ما إلو شعب، تخطّينا طلابه.");
                continue;
            }

    
            $counts = [];

            foreach ($sections as $section) {
                $counts[$section->id] = Student::where('section_id', $section->id)->count();
            }

            $index = 0;

            foreach ($students as $student) {
                $placed = false;
                for ($try = 0; $try < $sections->count(); $try++) {
                    $section = $sections[($index + $try) % $sections->count()];

                    if ($counts[$section->id] >= $section->capacity) {
                        continue;
                    }

                    $student->update(['section_id' => $section->id]);
                    $counts[$section->id]++;
                    $index = $index + $try + 1;
                    $assigned++;
                    $placed = true;
                    break;
                }

                if (!$placed) {
                    $this->command?->warn("ما ضل مكان بشعب الصف {$classId}.");
                    break;
                }
            }
        }

        $this->command?->info("تم توزيع {$assigned} طالب على الشعب.");
    }
}
