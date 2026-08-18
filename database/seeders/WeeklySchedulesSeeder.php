<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WeeklySchedulesSeeder extends Seeder
{
    public function run(): void
    {
        $teachers = DB::table('teachers')->pluck('id')->toArray();
        $assignments = DB::table('teacher_assignments')->pluck('id')->toArray();

        if (count($teachers) === 0) {
            $this->command->warn('لا يوجد معلمين.');
            return;
        }

        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'];
        $types = ['class', 'break', 'free'];

        $insertedCount = 0;
        $maxPeriods = 8; // عدد الحصص الأقصى في اليوم

        foreach ($teachers as $teacherId) {
            // إنشاء مجموعة عشوائية من الفترات لكل يوم
            foreach ($days as $day) {
                // عدد الفترات العشوائي بين 3-6
                $numPeriods = rand(3, 6);

                // اختيار فترات عشوائية دون تكرار
                $selectedPeriods = [];
                $availablePeriods = range(1, $maxPeriods);

                for ($i = 0; $i < $numPeriods; $i++) {
                    if (empty($availablePeriods)) break;

                    $randomIndex = array_rand($availablePeriods);
                    $period = $availablePeriods[$randomIndex];
                    unset($availablePeriods[$randomIndex]);
                    $availablePeriods = array_values($availablePeriods);

                    $selectedPeriods[] = $period;
                }

                // ترتيب الفترات تصاعدياً
                sort($selectedPeriods);

                foreach ($selectedPeriods as $period) {
                    // التحقق من عدم وجود السجل
                    $exists = DB::table('weekly_schedules')
                        ->where('teacher_id', $teacherId)
                        ->where('day_of_week', $day)
                        ->where('period_number', $period)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    // 50% chance for assignment
                    $assignmentId = null;
                    if (rand(0, 1) && count($assignments) > 0) {
                        $assignmentId = $assignments[array_rand($assignments)];
                    }

                    $startHour = 8 + ($period - 1);
                    $endHour = $startHour + 1;

                    try {
                        DB::table('weekly_schedules')->insert([
                            'teacher_id' => $teacherId,
                            'teacher_assignment_id' => $assignmentId,
                            'day_of_week' => $day,
                            'period_number' => $period,
                            'start_time' => sprintf('%02d:00:00', $startHour),
                            'end_time' => sprintf('%02d:00:00', $endHour),
                            'type' => $assignmentId ? 'class' : $types[array_rand($types)],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $insertedCount++;
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        $this->command->info('تم إنشاء الجداول الأسبوعية بنجاح!');
        $this->command->info('إجمالي الجداول المضافة: ' . $insertedCount);
    }
}
