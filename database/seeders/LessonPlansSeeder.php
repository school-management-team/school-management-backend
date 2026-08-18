<?php

namespace Database\Seeders;

use App\Models\LessonPlan;
use App\Models\WeeklySchedule;
use Illuminate\Database\Seeder;

class LessonPlansSeeder extends Seeder
{
    public function run(): void
    {
        if (WeeklySchedule::count() === 0) {
            $this->command->warn('لا يوجد جداول أسبوعية. قم بتشغيل WeeklySchedulesSeeder أولاً.');
            return;
        }

        $weeklySchedules = WeeklySchedule::take(20)->get();

        foreach ($weeklySchedules as $schedule) {
            // لكل جدول أسبوعي، خطة درس لكل أسبوع لمدة 4 أسابيع
            for ($i = 0; $i < 4; $i++) {
                $date = now()->addWeeks($i)->startOfWeek();

                // تحويل day_of_week إلى رقم (0=Sunday)
                $dayMap = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4];
                $dayOffset = $dayMap[$schedule->day_of_week] ?? 0;
                $lessonDate = $date->addDays($dayOffset);

                LessonPlan::firstOrCreate([
                    'weekly_schedule_id' => $schedule->id,
                    'date' => $lessonDate,
                ], [
                    'content' => fake()->paragraphs(2, true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
