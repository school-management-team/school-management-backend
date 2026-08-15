<?php
// app/Services/WeeklyScheduleService.php
namespace App\Services;

use App\Models\LessonPlan;
use App\Models\Teacher;
use App\Models\WeeklySchedule;

class WeeklyScheduleService
{
    private array $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'];

    // جدول يوم واحد، مع خطة الدرس الخاصة بذلك التاريخ بالضبط
public function dayOf(Teacher $teacher, string $dayOfWeek, string $date)
{
    $schedules = WeeklySchedule::where('teacher_id', $teacher->id)
        ->where('day_of_week', $dayOfWeek)
        ->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
        ->orderBy('period_number')
        ->get();

    $today = now()->toDateString();
    $now = now()->format('H:i:s');

    foreach ($schedules as $schedule) {
        if ($date < $today) {
            $schedule->status = 'completed';
        } elseif ($date > $today) {
            $schedule->status = 'upcoming';
        } else {
            if ($now < $schedule->start_time) {
                $schedule->status = 'upcoming';
            } elseif ($now > $schedule->end_time) {
                $schedule->status = 'completed';
            } else {
                $schedule->status = 'now';
            }
        }
    }

    $scheduleIds = $schedules->pluck('id');
    $plans = LessonPlan::whereIn('weekly_schedule_id', $scheduleIds)
        ->where('date', $date)
        ->pluck('content', 'weekly_schedule_id');

    foreach ($schedules as $schedule) {
        $schedule->lesson_plan = $plans->get($schedule->id);
    }

    return $schedules;
}
    // جدول الأسبوع كامل (بدون خطط، عشان يضل خفيف)
    public function week(Teacher $teacher)
    {
        $result = [];

        foreach ($this->days as $day) {
            $result[$day] = WeeklySchedule::where('teacher_id', $teacher->id)
                ->where('day_of_week', $day)
                ->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
                ->orderBy('period_number')
                ->get();
        }

        return $result;
    }

    public function findForTeacher(Teacher $teacher, int $scheduleId): ?WeeklySchedule
    {
        return WeeklySchedule::where('teacher_id', $teacher->id)->find($scheduleId);
    }

    // كتابة/تعديل خطة الدرس ليوم فعلي محدد
    public function saveLessonPlan(WeeklySchedule $schedule, string $date, string $content): LessonPlan
    {
        return LessonPlan::updateOrCreate(
            ['weekly_schedule_id' => $schedule->id, 'date' => $date],
            ['content' => $content]
        );
    }

    // ملخص حصص اليوم (العدد + الحصة القادمة)
    public function todaySummary(Teacher $teacher): array
    {
        $dayOfWeek = strtolower(now()->englishDayOfWeek);

        $todayClasses = WeeklySchedule::where('teacher_id', $teacher->id)
        ->where('day_of_week', $dayOfWeek)
        ->where('type', 'class')
        ->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
        ->orderBy('period_number')
        ->get();

        $now = now()->format('H:i:s');

        $nextClass = $todayClasses->first(function ($schedule) use ($now) {
            return $schedule->start_time > $now;
        });

        return [
        'total_today' => $todayClasses->count(),
        'next_class' => $nextClass,
        ];
    }
}
