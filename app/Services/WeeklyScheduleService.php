<?php
namespace App\Services;

use App\Models\LessonPlan;

use App\Models\Student;

use App\Models\LessonSubstitution;

use App\Models\Teacher;
use App\Models\WeeklySchedule;

class WeeklyScheduleService
{
    private array $days;

    public function __construct()
    {
        $this->days = config('school.school_days');
    }

    /**
     * جدول شعبة كامل كشبكة أيام × حصص. بيستعملها الموجّه وولي الأمر —
     * نفس المصدر حتى ما يختلف اللي بيشوفوه.
     */
    public function sectionWeek(int $sectionId): array
    {
        $lessons = WeeklySchedule::where('section_id', $sectionId)
            ->with('teacher.user:id,user_name', 'teacherAssignment.subject:id,name')
            ->get();

        // منرتّبهم بمفتاح "اليوم-رقم الحصة" حتى نلاقي حصة أي خانة بسرعة
        $bySlot = [];

        foreach ($lessons as $lesson) {
            $bySlot[$lesson->day_of_week.'-'.$lesson->period_number] = $lesson;
        }

        $grid = [];

        foreach ($this->days as $day) {
            $slots = [];

            foreach (config('school.periods') as $number => $period) {
                $slot = [
                    'period_number' => $number,
                    'start_time' => $period['start'],
                    'end_time' => $period['end'],
                    'type' => $period['type'],
                ];

                $key = $day.'-'.$number;

                // الخانة الفاضية بتضل بمعلوماتها الأساسية بدون مادة ومعلم
                if (isset($bySlot[$key])) {
                    $lesson = $bySlot[$key];

                    $slot['weekly_schedule_id'] = $lesson->id;
                    $slot['subject'] = $lesson->teacherAssignment ? $lesson->teacherAssignment->subject->name : null;
                    $slot['teacher_id'] = $lesson->teacher_id;
                    $slot['teacher_name'] = $lesson->teacher ? $lesson->teacher->user->user_name : null;
                }

                $slots[] = $slot;
            }

            $grid[$day] = $slots;
        }

        return ['grid' => $grid, 'filled_slots' => $lessons->count()];
    }

    /**
     * حصص الشعبة بيوم فعلي — مع المعلم البديل إذا كان في تعويض مسجّل.
     * هذا اللي يهم ولي الأمر: مين رح يعطي ابنه اليوم فعلياً.
     */
    public function sectionDay(int $sectionId, string $dayOfWeek, string $date): array
    {
        $lessons = WeeklySchedule::where('section_id', $sectionId)
            ->where('day_of_week', $dayOfWeek)
            ->where('type', 'class')
            ->with('teacher.user:id,user_name', 'teacherAssignment.subject:id,name')
            ->orderBy('period_number')
            ->get();

        $substitutions = LessonSubstitution::forDate($date)
            ->active()
            ->whereIn('weekly_schedule_id', $lessons->pluck('id'))
            ->with('substituteTeacher.user:id,user_name')
            ->get()
            ->keyBy('weekly_schedule_id');

        $result = [];

        foreach ($lessons as $lesson) {
            $substitution = $substitutions->get($lesson->id);

            $substituteName = null;

            if ($substitution && $substitution->substituteTeacher) {
                $substituteName = $substitution->substituteTeacher->user->user_name;
            }

            $result[] = [
                'weekly_schedule_id' => $lesson->id,
                'period_number' => $lesson->period_number,
                'start_time' => $lesson->start_time,
                'end_time' => $lesson->end_time,
                'subject' => $lesson->teacherAssignment ? $lesson->teacherAssignment->subject->name : null,
                'teacher_name' => $lesson->teacher ? $lesson->teacher->user->user_name : null,
                'is_substituted' => $substitution !== null,
                'substitute_teacher_name' => $substituteName,
            ];
        }

        return $result;
    }

    // ==================== توابع المعلم (بدون تغيير) ====================

    public function dayOf(Teacher $teacher, string $dayOfWeek, string $date)
    {
        $schedules = WeeklySchedule::where('teacher_id', $teacher->id)
            ->where('day_of_week', $dayOfWeek)
            ->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
            ->orderBy('period_number')
            ->get();

        $this->applyStatus($schedules, $date);

        $scheduleIds = $schedules->pluck('id');
        $plans = LessonPlan::whereIn('weekly_schedule_id', $scheduleIds)
            ->where('date', $date)
            ->pluck('content', 'weekly_schedule_id');

        foreach ($schedules as $schedule) {
            $schedule->lesson_plan = $plans->get($schedule->id);
        }
        return $schedules->map(function ($schedule) {
        return $this->formatScheduleItem($schedule);
    });


    }



public function week(Teacher $teacher)
{
    $result = [];
    foreach ($this->days as $day) {
        $schedules = WeeklySchedule::where('teacher_id', $teacher->id)
            ->where('day_of_week', $day)
            ->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
            ->orderBy('period_number')
            ->get();

        $this->applyStatus($schedules, now()->toDateString());

        $result[$day] = $schedules->map(fn ($schedule) => $this->formatScheduleItem($schedule));
    }
    return $result;
}
    public function findForTeacher(Teacher $teacher, int $scheduleId): ?WeeklySchedule
    {
        return WeeklySchedule::where('teacher_id', $teacher->id)->find($scheduleId);
    }

    public function saveLessonPlan(WeeklySchedule $schedule, string $date, string $content): LessonPlan
    {
        return LessonPlan::updateOrCreate(
            ['weekly_schedule_id' => $schedule->id, 'date' => $date],
            ['content' => $content]
        );
    }

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
        $nextClass = $todayClasses->first(fn ($schedule) => $schedule->start_time > $now);

        return ['total_today' => $todayClasses->count(), 'next_class' => $nextClass];
    }

    // ==================== توابع الطالب (جديدة، بنفس الملف) ====================

    // جدول يوم واحد لشعبة الطالب: حصص فعلية (حسب المادة والمعلم) + استراحات مشتركة، بدون فراغات المعلمين
    public function dayForStudent(Student $student, string $dayOfWeek, string $date)
    {
        if (!$student->section_id) {
            return collect();
        }

        $classes = WeeklySchedule::where('day_of_week', $dayOfWeek)
            ->where('type', 'class')
            ->whereHas('teacherAssignment', function ($query) use ($student) {
                $query->where('section_id', $student->section_id);
            })
            ->with('teacherAssignment.subject:id,name', 'teacherAssignment.teacher.user:id,user_name')
            ->get();

        $breaks = WeeklySchedule::where('day_of_week', $dayOfWeek)
            ->where('type', 'break')
            ->get()
            ->unique('period_number');

        $schedule = $classes->concat($breaks)->sortBy('period_number')->values();

        $this->applyStatus($schedule, $date);

        return $schedule;
    }

    public function weekForStudent(Student $student)
    {
        $result = [];
        foreach ($this->days as $day) {
            $result[$day] = $this->dayForStudent($student, $day, now()->toDateString());
        }
        return $result;
    }



public function todaySummaryForStudent(Student $student): array
{
    $dayOfWeek = strtolower(now()->englishDayOfWeek);
    $today = $this->dayForStudent($student, $dayOfWeek, now()->toDateString());
    $classesOnly = $today->where('type', 'class')->values();

    $classesOnly->load(['teacherAssignment.subject', 'teacherAssignment.section.schoolClass']);

    $now = now()->format('H:i:s');
    $nextClass = $classesOnly->first(fn ($c) => $c->start_time > $now);

    // الحصص اللي لسا ما خلصت (جارية أو قادمة)
    $remaining = $classesOnly->filter(fn ($c) => $c->end_time > $now);

    $remainingMinutes = 0;
    foreach ($remaining as $class) {
        $start = \Carbon\Carbon::parse(max($class->start_time, $now));
        $end = \Carbon\Carbon::parse($class->end_time);
        $remainingMinutes += $start->diffInMinutes($end);
    }

    return [
        'next_class' => $nextClass,
        'remaining_hours_today' => round($remainingMinutes / 60, 1),
    ];
}

    // ==================== دالة مشتركة (تحسب upcoming/now/completed حسب التاريخ المطلوب) ====================

    private function applyStatus($schedules, string $date): void
    {
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
    }



private function formatScheduleItem($schedule)
{
    // تحويل إلى array
    $data = $schedule->toArray();

    // إذا كان النوع ليس class، نضع القيم null
    if ($schedule->type !== 'class') {
        $data['subject_name'] = null;
        $data['section_name'] = null;
        $data['class_name'] = null;
        $data['teacher_assignment'] = null;
    } else {
        // للـ class نضيف البيانات من العلاقات
        if ($schedule->teacherAssignment) {
            $data['subject_name'] = $schedule->teacherAssignment->subject->name ?? null;
            $data['section_name'] = $schedule->teacherAssignment->section->name ?? null;
            $data['class_name'] = $schedule->teacherAssignment->section->schoolClass->name ?? null;
        }
    }

    return $data;
}
}
