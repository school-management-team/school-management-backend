<?php

namespace Database\Seeders;

use App\Models\TeacherAssignment;
use App\Models\WeeklySchedule;
use Illuminate\Database\Seeder;

class WeeklyScheduleSeeder extends Seeder
{
    
    public function run(): void
    {
        $days = config('school.school_days');
        $periods = collect(config('school.periods'))->where('type', 'class');

    
        WeeklySchedule::query()->delete();

        $bySection = TeacherAssignment::all()->groupBy('section_id');

        $bySection = $bySection->sortByDesc(function ($assignments) {
            return $assignments->count();
        });

        $teacherBusy = [];
        $created = 0;

        foreach ($bySection as $assignments) {
            $pool = $assignments->values();
            $cursor = 0;

            foreach ($days as $day) {
                foreach ($periods as $number => $period) {
                
                    for ($try = 0; $try < $pool->count(); $try++) {
                        $assignment = $pool[($cursor + $try) % $pool->count()];
                        $slot = $assignment->teacher_id.'-'.$day.'-'.$number;

                        if (isset($teacherBusy[$slot])) {
                            continue;
                        }

                        WeeklySchedule::create([
                            'teacher_id' => $assignment->teacher_id,
                            'teacher_assignment_id' => $assignment->id,
                            'day_of_week' => $day,
                            'period_number' => $number,
                            'start_time' => $period['start'],
                            'end_time' => $period['end'],
                            'type' => 'class',
                        ]);

                        $teacherBusy[$slot] = true;
                        $cursor = ($cursor + $try + 1) % $pool->count();
                        $created++;
                        break;
                    }
                }
            }
        }

        $this->command?->info("تم إنشاء {$created} حصة بدون أي تضارب.");
    }
}
