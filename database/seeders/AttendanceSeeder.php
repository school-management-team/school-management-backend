<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\Section;
use App\Models\Supervisor;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        if (Student::count() === 0 || Section::count() === 0) {
            $this->command->warn('لا يوجد طلاب أو شعب. قم بتشغيل UserSeeder أولاً.');
            return;
        }

        $this->command->info('بدء إنشاء سجلات الحضور...');

        $supervisorId = Supervisor::first()?->id ?? 1;
        $students = Student::take(10)->get();
        $statuses = ['present', 'present', 'present', 'present', 'absent', 'present', 'late', 'present', 'excused', 'present'];
        $excuses = ['مرض', 'عذر عائلي', 'ظروف طارئة', 'موعد طبي', null, null, null];


        // 1. إنشاء 50 سجل حضور عشوائي (تجنب التكرار)

        $this->command->info('إنشاء 50 سجل حضور عشوائي...');

        $existingRecords = [];
        $createdCount = 0;

        for ($i = 0; $i < 100; $i++) {
            if ($createdCount >= 50) break;

            $student = $students->random();
            $date = Carbon::now()->subDays(rand(1, 30))->format('Y-m-d');

            // التحقق من عدم وجود سجل مكرر
            $key = $student->id . '-' . $date;

            if (in_array($key, $existingRecords)) {
                continue;
            }

            // التحقق من قاعدة البيانات
            $existing = Attendance::where('student_id', $student->id)
                ->where('date', $date)
                ->first();

            if ($existing) {
                $existingRecords[] = $key;
                continue;
            }

            $existingRecords[] = $key;
            $createdCount++;

            $status = $statuses[array_rand($statuses)];

            Attendance::create([
                'student_id' => $student->id,
                'section_id' => $student->section_id,
                'supervisor_id' => $supervisorId,
                'date' => $date,
                'status' => $status,
                'excuse' => $status === 'absent' ? $excuses[array_rand($excuses)] : null,
            ]);
        }

        // 2. سجلات حضور لشهر كامل لطلاب محددين

        $this->command->info('إنشاء سجلات حضور لشهر كامل...');

        $students = Student::with('section')->take(5)->get();
        $startDate = Carbon::now()->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();

        foreach ($students as $student) {
            $currentDate = clone $startDate;

            while ($currentDate <= $endDate) {
                // تخطي أيام الجمعة والسبت
                if ($currentDate->dayOfWeek === Carbon::FRIDAY || $currentDate->dayOfWeek === Carbon::SATURDAY) {
                    $currentDate->addDay();
                    continue;
                }

                $dateStr = $currentDate->format('Y-m-d');

                // التحقق من عدم وجود سجل مسبق
                $existing = Attendance::where('student_id', $student->id)
                    ->where('date', $dateStr)
                    ->first();

                if (!$existing) {
                    $status = $this->getRealisticStatus($currentDate, $student->id);

                    Attendance::create([
                        'student_id' => $student->id,
                        'section_id' => $student->section_id,
                        'supervisor_id' => $supervisorId,
                        'date' => $dateStr,
                        'status' => $status,
                        'excuse' => $status === 'absent' ? $this->getRandomExcuse() : null,
                    ]);
                }

                $currentDate->addDay();
            }

            $this->command->line("تم إنشاء سجلات للطالب {$student->id}");
        }


        //  سجلات حضور لشعبة محددة (للاختبار)

        $this->command->info('إنشاء سجلات حضور لشعبة محددة...');

        $section = Section::inRandomOrder()->first();
        $studentsInSection = Student::where('section_id', $section->id)->take(3)->get();
        $dates = ['2026-07-20', '2026-07-21', '2026-07-22', '2026-07-23', '2026-07-24'];

        foreach ($studentsInSection as $student) {
            foreach ($dates as $date) {
                // التحقق من عدم وجود سجل مسبق
                $existing = Attendance::where('student_id', $student->id)
                    ->where('date', $date)
                    ->first();

                if (!$existing) {
                    $status = $this->getStatusForDate($date);
                    Attendance::create([
                        'student_id' => $student->id,
                        'section_id' => $section->id,
                        'supervisor_id' => $supervisorId,
                        'date' => $date,
                        'status' => $status,
                        'excuse' => $status === 'absent' ? 'مرض' : null,
                    ]);
                }
            }
        }

        $this->command->info('تم إنشاء سجلات الحضور بنجاح!');
        $this->command->info('إجمالي السجلات: ' . Attendance::count());
    }

    private function getRealisticStatus($date, int $studentId): string
    {
        $dayOfWeek = $date->dayOfWeek;

        if ($dayOfWeek === Carbon::SUNDAY || $dayOfWeek === Carbon::THURSDAY) {
            $absentChance = 30;
        } else {
            $absentChance = 10;
        }

        $studentAbsentFactor = ($studentId % 3 === 0) ? 20 : 0;
        $totalAbsentChance = $absentChance + $studentAbsentFactor;

        $random = rand(1, 100);

        if ($random <= 5) {
            return 'excused';
        } elseif ($random <= 10) {
            return 'late';
        } elseif ($random <= 10 + $totalAbsentChance) {
            return 'absent';
        }

        return 'present';
    }

    private function getStatusForDate(string $date): string
    {
        $statuses = [
            '2026-07-20' => 'present',
            '2026-07-21' => 'present',
            '2026-07-22' => 'absent',
            '2026-07-23' => 'late',
            '2026-07-24' => 'present',
        ];

        return $statuses[$date] ?? 'present';
    }

    private function getRandomExcuse(): string
    {
        $excuses = ['مرض', 'عذر عائلي', 'ظروف طارئة', 'موعد طبي', 'سفر'];
        return $excuses[array_rand($excuses)];
    }
}

