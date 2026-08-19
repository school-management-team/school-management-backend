<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\Supervisor;

use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Services\SchoolCalendarService;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{

    public function run(): void
    {
        $students = Student::whereNotNull('section_id')->get();

        if ($students->isEmpty()) {
            $this->command?->warn('لا يوجد طلاب موزّعين على شعب. شغّل StudentSectionSeeder أولاً.');
            return;
        }

        $supervisor = Supervisor::first();


        if (!$supervisor) {
            $this->command?->warn('لا يوجد موجّه. شغّل SupervisorSeeder أولاً.');
            return;
        }

        Attendance::query()->delete();

        $calendar = app(SchoolCalendarService::class);


        $schoolDays = [];
        $cursor = Carbon::today();

        while (count($schoolDays) < 30) {
            $date = $cursor->toDateString();

            if ($calendar->isSchoolDay($date)) {
                $schoolDays[] = $date;
            }

            $cursor->subDay();


            if ($cursor->lt(Carbon::today()->subDays(120))) {
                break;
            }
        }

        $created = 0;
        $counts = [];

        foreach ($students as $student) {
            foreach ($schoolDays as $date) {
                $status = $this->pickStatus();

                Attendance::create([
                    'student_id' => $student->id,
                    'section_id' => $student->section_id,
                    'supervisor_id' => $supervisor->id,
                    'date' => $date,
                    'status' => $status,
                    'excuse' => in_array($status, ['absent', 'excused']) ? $this->pickExcuse() : null,
                    'left_at' => $status === 'early_leave' ? '11:30' : null,
                ]);

                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $created++;
            }
        }

        $summary = [];

        foreach ($counts as $status => $count) {
            $summary[] = $status.': '.$count;
        }

        $this->command?->info("سجلات حضور الطلاب: {$created} على ".count($schoolDays).' يوم دوام');
        $this->command?->line('  '.implode(' | ', $summary));

        $this->seedTeachers($schoolDays, $supervisor->id);
    }

    /**
     * حضور المعلمين — بدونه ما بيشتغل نظام التعويض إطلاقاً، لأنه بيعتمد
     * عليه ليعرف مين غايب (حصصه بدها تعويض) ومين موجود بالمدرسة (بيقدر يعوّض).
     */
    private function seedTeachers(array $schoolDays, int $supervisorId): void
    {
        $teachers = Teacher::all();

        if ($teachers->isEmpty()) {
            return;
        }

        TeacherAttendance::query()->delete();

        $created = 0;
        $absent = 0;

        foreach ($schoolDays as $index => $date) {
            foreach ($teachers as $position => $teacher) {
                // منخلّي معلم واحد غايب كل يوم بالتناوب، والباقي حاضرين
                $isAway = ($index + $position) % $teachers->count() === 0;
                $status = $isAway ? 'absent' : 'present';

                TeacherAttendance::create([
                    'teacher_id' => $teacher->id,
                    'supervisor_id' => $supervisorId,
                    'date' => $date,
                    'status' => $status,
                    'excuse' => $isAway ? 'عذر صحي' : null,
                ]);

                $created++;

                if ($isAway) {
                    $absent++;
                }
            }
        }

        $this->command?->info("سجلات حضور المعلمين: {$created} (منها {$absent} غياب)");
    }


    private function pickStatus(): string
    {
        $roll = rand(1, 100);

        if ($roll <= 78) {
            return 'present';
        }

        if ($roll <= 87) {
            return 'absent';
        }

        if ($roll <= 93) {
            return 'late';
        }

        if ($roll <= 97) {
            return 'excused';
        }

        return 'early_leave';
    }

    private function pickExcuse(): string
    {
        $excuses = ['مرض', 'عذر عائلي', 'ظروف طارئة', 'موعد طبي', 'سفر'];

        return $excuses[array_rand($excuses)];

    }
}
