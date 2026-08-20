<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\WeeklySchedule;
use App\Services\SchoolCalendarService;
use App\Services\WeeklyScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WeeklyScheduleController extends Controller
{
    protected $calendarService;

    public function __construct(SchoolCalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    /** تعريف الحصص وأيام الدوام — تتعبّى فيها الواجهة شبكة الجدول */
    public function periods()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'days' => config('school.school_days'),
                'periods' => $this->periodList(),
            ],
        ]);
    }

    /**
     * الجدول الأسبوعي لشعبة: شبكة كاملة أيام × حصص.
     *
     * الشبكة بترجع دايماً كاملة حتى لو الشعبة ما إلها جدول — الخانات بتكون
     * فاضية. منوضّح هالحالة بـ message و is_empty حتى ما تبيّن الشبكة
     * الفاضية وكأنها جدول موجود.
     */
    public function sectionSchedule(Section $section, WeeklyScheduleService $schedules)
    {
        $section->load('schoolClass');
        $week = $schedules->sectionWeek($section->id);

        $filled = $week['filled_slots'];
        $totalSlots = $this->classPeriodCount();

        if ($filled === 0) {
            $message = 'لا يوجد جدول مسجّل لهذه الشعبة بعد';
        } else {
            $message = 'عدد الحصص المسجّلة: '.$filled.' من أصل '.$totalSlots;
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'class_name' => $section->schoolClass ? $section->schoolClass->name : null,
                ],
                'schedule' => $week['grid'],
                'filled_slots' => $filled,
                'total_slots' => $totalSlots,
                'empty_slots' => $totalSlots - $filled,
                'is_empty' => $filled === 0,
            ],
        ]);
    }

    /** الجدول الأسبوعي لمعلم */
    public function teacherSchedule(Teacher $teacher)
    {
        $lessons = WeeklySchedule::where('teacher_id', $teacher->id)
            ->with('teacherAssignment.subject:id,name', 'section.schoolClass')
            ->get();

        $teacher->load('user:id,user_name');

        // منرتّبهم بمفتاح "اليوم-رقم الحصة" حتى نلاقي حصة أي خانة بسرعة
        $bySlot = [];

        foreach ($lessons as $lesson) {
            $bySlot[$lesson->day_of_week.'-'.$lesson->period_number] = $lesson;
        }

        $grid = [];

        foreach (config('school.school_days') as $day) {
            $slots = [];

            foreach (config('school.periods') as $number => $period) {
                $slot = [
                    'period_number' => $number,
                    'start_time' => $period['start'],
                    'end_time' => $period['end'],
                    'type' => $period['type'],
                ];

                $key = $day.'-'.$number;

                if (isset($bySlot[$key])) {
                    $lesson = $bySlot[$key];
                    $section = $lesson->section;

                    $slot['weekly_schedule_id'] = $lesson->id;
                    $slot['subject'] = $lesson->teacherAssignment ? $lesson->teacherAssignment->subject->name : null;
                    $slot['class_name'] = $section && $section->schoolClass ? $section->schoolClass->name : null;
                    $slot['section_name'] = $section ? $section->name : null;
                }

                $slots[] = $slot;
            }

            $grid[$day] = $slots;
        }

        $filled = $lessons->count();
        $totalSlots = $this->classPeriodCount();

        if ($filled === 0) {
            $message = 'لا توجد حصص مسندة لهذا المعلم بعد';
        } else {
            $message = 'نصاب المعلم: '.$filled.' حصة';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'teacher' => [
                    'id' => $teacher->id,
                    'name' => $teacher->user ? $teacher->user->user_name : null,
                ],
                'schedule' => $grid,
                'filled_slots' => $filled,
                'total_slots' => $totalSlots,
                'free_slots' => $totalSlots - $filled,
                'is_empty' => $filled === 0,
            ],
        ]);
    }

    /**
     * مين فاضي بخانة معيّنة — بتساعد الموجّه وهو عم يبني الجدول.
     * (مالها علاقة بالتعويض: هون ما منفحص الحضور، منفحص الجدول بس)
     */
    public function freeTeachers(Request $request)
    {
        $validated = $request->validate([
            'day_of_week' => ['required', 'in:'.implode(',', config('school.school_days'))],
            'period_number' => 'required|integer',
            'subject_id' => 'sometimes|exists:subjects,id',
        ]);

        if (!$this->classPeriod($validated['period_number'])) {
            return $this->invalidPeriod($validated['period_number']);
        }

        $day = $validated['day_of_week'];
        $periodNumber = $validated['period_number'];
        $period = $this->classPeriod($periodNumber);

        // كم معلم منفحص أصلاً؟ (بعد فلتر المادة إن وُجد) — لازمنا للرسالة
        $pool = Teacher::query();

        if ($request->filled('subject_id')) {
            $pool->where('subject_id', $request->subject_id);
        }

        $poolCount = $pool->count();

        $query = Teacher::with('user:id,user_name', 'subject:id,name', 'stage:id,name');

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        // المعلم الفاضي = ما عندو ولا حصة بهاليوم وهالرقم بالجدول الأسبوعي
        $query->whereDoesntHave('weeklySchedules', function ($schedule) use ($day, $periodNumber) {
            $schedule->where('day_of_week', $day)->where('period_number', $periodNumber);
        });

        $found = $query->withCount('weeklySchedules')->get();

        $teachers = [];

        foreach ($found as $teacher) {
            $teachers[] = [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->user ? $teacher->user->user_name : null,
                'subject' => $teacher->subject ? $teacher->subject->name : null,
                'stage' => $teacher->stage ? $teacher->stage->name : null,
                'weekly_lessons_count' => $teacher->weekly_schedules_count,
            ];
        }

        // الأقل نصاباً أولاً — حتى ينوزّع العبء بالعدل وقت بناء الجدول
        usort($teachers, function ($a, $b) {
            return $a['weekly_lessons_count'] - $b['weekly_lessons_count'];
        });

        $free = count($teachers);
        $busy = $poolCount - $free;
        $dayLabel = $this->calendarService->dayLabel($day);
        $scope = $request->filled('subject_id') ? 'معلمي هذه المادة' : 'المعلمين';

        if ($poolCount === 0) {
            $message = 'لا يوجد معلمون مطابقون للفلتر المُرسل';
        } elseif ($free === 0) {
            $message = "كل {$scope} ({$poolCount}) عندهم حصة يوم {$dayLabel} بالحصة {$periodNumber}";
        } else {
            $message = "من أصل {$poolCount} من {$scope}، {$free} فاضي يوم {$dayLabel} بالحصة {$periodNumber}";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'day_of_week' => $day,
                'day_label' => $dayLabel,
                'period_number' => $periodNumber,
                'starts_at' => $period['start'],
                'ends_at' => $period['end'],
                'free_teachers' => $teachers,
                'total' => $free,
                'busy_count' => $busy,
                'checked_count' => $poolCount,
            ],
        ]);
    }

    /** إضافة حصة للجدول */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'teacher_assignment_id' => 'required|exists:teacher_assignments,id',
            'day_of_week' => ['required', 'in:'.implode(',', config('school.school_days'))],
            'period_number' => 'required|integer',
        ]);

        $period = $this->classPeriod($validated['period_number']);

        if (!$period) {
            return $this->invalidPeriod($validated['period_number']);
        }

        $assignment = TeacherAssignment::findOrFail($validated['teacher_assignment_id']);

        // التكليف نفسه صالح؟ (المادة مقررة بالمرحلة، ومرحلة المعلم تطابق)
        $invalid = $this->invalidAssignment($assignment);

        if ($invalid) {
            return $invalid;
        }

        return DB::transaction(function () use ($assignment, $validated, $period) {
            $conflicts = $this->conflicts(
                $assignment->teacher_id,
                $assignment->section_id,
                $validated['day_of_week'],
                $validated['period_number']
            );

            if ($conflicts) {
                return response()->json([
                    'success' => false,
                    'message' => 'يوجد تعارض في هذه الخانة',
                    'data' => ['conflicts' => $conflicts],
                ], 422);
            }

            $lesson = WeeklySchedule::create([
                'teacher_id' => $assignment->teacher_id,
                'teacher_assignment_id' => $assignment->id,
                'day_of_week' => $validated['day_of_week'],
                'period_number' => $validated['period_number'],
                'start_time' => $period['start'],
                'end_time' => $period['end'],
                'type' => 'class',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تمت إضافة الحصة للجدول',
                'data' => $lesson->load([
                    'teacher.user:id,user_name',
                    'teacherAssignment.subject:id,name',
                    'section.schoolClass',
                ]),
            ], 201);
        });
    }

    /**
     * بناء جدول كامل دفعة واحدة.
     * إذا في أي تعارض، ما منحفظ ولا حصة ومنرجّع كل التعارضات مرة وحدة.
     */
    public function storeBulk(Request $request)
    {
        $validated = $request->validate([
            'lessons' => 'required|array|min:1',
            'lessons.*.teacher_assignment_id' => 'required|exists:teacher_assignments,id',
            'lessons.*.day_of_week' => ['required', 'in:'.implode(',', config('school.school_days'))],
            'lessons.*.period_number' => 'required|integer',
        ]);

        $assignmentIds = [];

        foreach ($validated['lessons'] as $item) {
            $assignmentIds[] = $item['teacher_assignment_id'];
        }

        $assignments = TeacherAssignment::whereIn('id', $assignmentIds)->get()->keyBy('id');

        $errors = [];
        $rows = [];
        $takenTeacherSlots = [];
        $takenSectionSlots = [];

        foreach ($validated['lessons'] as $index => $item) {
            $period = $this->classPeriod($item['period_number']);

            if (!$period) {
                $errors[] = [
                    'index' => $index,
                    'reason' => "رقم الحصة {$item['period_number']} غير صالح أو أنه وقت استراحة",
                ];
                continue;
            }

            $assignment = $assignments->get($item['teacher_assignment_id']);

            // التكليف نفسه صالح؟ (المادة مقررة بالمرحلة)
            $invalid = $this->invalidAssignment($assignment);

            if ($invalid) {
                $body = $invalid->getData(true);

                $errors[] = [
                    'index' => $index,
                    'reason' => $body['message'],
                    'assignment_id' => $assignment->id,
                ];
                continue;
            }

            $day = $item['day_of_week'];
            $slot = $day.'-'.$item['period_number'];
            $teacherSlot = $assignment->teacher_id.'-'.$slot;
            $sectionSlot = $assignment->section_id.'-'.$slot;

            // تعارض جوّا نفس الدفعة
            if (isset($takenTeacherSlots[$teacherSlot])) {
                $errors[] = [
                    'index' => $index,
                    'reason' => 'المعلم مكرر في نفس الخانة ضمن نفس الطلب',
                    'conflicts_with_index' => $takenTeacherSlots[$teacherSlot],
                ];
                continue;
            }

            if (isset($takenSectionSlots[$sectionSlot])) {
                $errors[] = [
                    'index' => $index,
                    'reason' => 'الشعبة مكررة في نفس الخانة ضمن نفس الطلب',
                    'conflicts_with_index' => $takenSectionSlots[$sectionSlot],
                ];
                continue;
            }

            // تعارض مع المحفوظ سابقاً
            $conflicts = $this->conflicts(
                $assignment->teacher_id,
                $assignment->section_id,
                $day,
                $item['period_number']
            );

            if ($conflicts) {
                $errors[] = [
                    'index' => $index,
                    'reason' => 'تعارض مع الجدول المحفوظ',
                    'conflicts' => $conflicts,
                ];
                continue;
            }

            $takenTeacherSlots[$teacherSlot] = $index;
            $takenSectionSlots[$sectionSlot] = $index;

            $rows[] = [
                'teacher_id' => $assignment->teacher_id,
                'teacher_assignment_id' => $assignment->id,
                'day_of_week' => $day,
                'period_number' => $item['period_number'],
                'start_time' => $period['start'],
                'end_time' => $period['end'],
                'type' => 'class',
            ];
        }

        if ($errors) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم حفظ أي حصة بسبب وجود تعارضات',
                'data' => ['errors' => $errors],
            ], 422);
        }

        $created = DB::transaction(function () use ($rows) {
            $lessons = [];

            // create() مش insert() حتى يشتغل هوك مزامنة section_id بالموديل
            foreach ($rows as $row) {
                $lessons[] = WeeklySchedule::create($row);
            }

            return $lessons;
        });

        return response()->json([
            'success' => true,
            'message' => 'تم بناء الجدول بنجاح',
            'data' => [
                'created_count' => count($created),
                'lessons' => $created,
            ],
        ], 201);
    }

    /** نقل حصة لخانة تانية */
    public function update(Request $request, WeeklySchedule $schedule)
    {
        $validated = $request->validate([
            'day_of_week' => ['sometimes', 'in:'.implode(',', config('school.school_days'))],
            'period_number' => 'sometimes|integer',
            'teacher_assignment_id' => 'sometimes|exists:teacher_assignments,id',
        ]);

        if (count($validated) === 0) {
            return $this->nothingToUpdate();
        }

        $day = $validated['day_of_week'] ?? $schedule->day_of_week;
        $periodNumber = $validated['period_number'] ?? $schedule->period_number;
        $period = $this->classPeriod($periodNumber);

        if (!$period) {
            return $this->invalidPeriod($periodNumber);
        }

        $assignment = isset($validated['teacher_assignment_id'])
            ? TeacherAssignment::findOrFail($validated['teacher_assignment_id'])
            : $schedule->teacherAssignment;

        if (!$assignment) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الحصة غير مرتبطة بتكليف، حدد teacher_assignment_id',
            ], 422);
        }

        $invalid = $this->invalidAssignment($assignment);

        if ($invalid) {
            return $invalid;
        }

        return DB::transaction(function () use ($schedule, $assignment, $day, $periodNumber, $period) {
            $conflicts = $this->conflicts(
                $assignment->teacher_id,
                $assignment->section_id,
                $day,
                $periodNumber,
                $schedule->id
            );

            if ($conflicts) {
                return response()->json([
                    'success' => false,
                    'message' => 'يوجد تعارض في هذه الخانة',
                    'data' => ['conflicts' => $conflicts],
                ], 422);
            }

            $schedule->update([
                'teacher_id' => $assignment->teacher_id,
                'teacher_assignment_id' => $assignment->id,
                'day_of_week' => $day,
                'period_number' => $periodNumber,
                'start_time' => $period['start'],
                'end_time' => $period['end'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم نقل الحصة بنجاح',
                'data' => $schedule->fresh([
                    'teacher.user:id,user_name',
                    'teacherAssignment.subject:id,name',
                    'section.schoolClass',
                ]),
            ]);
        });
    }

    public function destroy(WeeklySchedule $schedule)
    {
        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الحصة من الجدول',
        ]);
    }

    // ==================== أدوات داخلية ====================

    /**
     * التكليف نفسه صالح؟
     *
     * التكليف المفروض يكون انفحص وقت إنشائه، بس تكاليف قديمة (أو مزروعة)
     * ممكن تكون خالفت القاعدة قبل ما تنضاف. فمنفحص هون كمان قبل ما نبني
     * عليها جدولاً — أرخص من اكتشاف "التاريخ لصف ابتدائي" بعد النشر.
     *
     * بيرجّع استجابة رفض إذا في مخالفة، وإلا null.
     */
    private function invalidAssignment(TeacherAssignment $assignment)
    {
        $assignment->loadMissing('subject', 'teacher.stage', 'section.schoolClass.stage');

        $class = $assignment->section ? $assignment->section->schoolClass : null;

        if (!$class || !$class->stage_id || !$assignment->subject) {
            return null;
        }

        $stageName = $class->stage ? $class->stage->name : '-';

        // المادة مقررة بمرحلة هذا الصف؟
        $taught = $assignment->subject->stages()->where('stages.id', $class->stage_id)->exists();

        if (!$taught) {
            return response()->json([
                'success' => false,
                'message' => "لا يمكن جدولة هذه الحصة: مادة ({$assignment->subject->name}) "
                    ."غير مقررة في مرحلة ({$stageName}). التكليف رقم {$assignment->id} مخالف ويجب حذفه",
                'data' => [
                    'assignment_id' => $assignment->id,
                    'subject' => $assignment->subject->name,
                    'class_stage' => $stageName,
                    'class_name' => $class->name,
                ],
            ], 422);
        }

        // ومرحلة المعلم تطابق مرحلة الصف؟
        if ($assignment->teacher && $assignment->teacher->stage_id !== $class->stage_id) {
            $teacherStage = $assignment->teacher->stage ? $assignment->teacher->stage->name : '-';

            return response()->json([
                'success' => false,
                'message' => "لا يمكن جدولة هذه الحصة: مرحلة المعلم ({$teacherStage}) "
                    ."لا تطابق مرحلة الصف ({$stageName}). التكليف رقم {$assignment->id} مخالف",
                'data' => [
                    'assignment_id' => $assignment->id,
                    'teacher_stage' => $teacherStage,
                    'class_stage' => $stageName,
                ],
            ], 422);
        }

        return null;
    }

    /**
     * بيرجّع التعارضات بخانة معيّنة: المعلم مشغول، أو الشعبة مشغولة.
     * الداتابيز عندها unique على الحالتين، بس منفحص هون حتى نرجّع رسالة مفهومة
     * بدل ما نطلّع QueryException.
     */
    private function conflicts(int $teacherId, int $sectionId, string $day, int $period, ?int $ignoreId = null): array
    {
        $found = [];

        // المعلم مشغول بنفس الخانة؟
        $teacherBusy = $this->slotQuery($day, $period, $ignoreId)
            ->where('teacher_id', $teacherId)
            ->first();

        if ($teacherBusy) {
            $section = $teacherBusy->section;

            $found[] = [
                'type' => 'teacher_busy',
                'message' => 'المعلم لديه حصة أخرى في نفس اليوم ونفس الحصة',
                'weekly_schedule_id' => $teacherBusy->id,
                'subject' => $teacherBusy->teacherAssignment ? $teacherBusy->teacherAssignment->subject->name : null,
                'class_name' => $section && $section->schoolClass ? $section->schoolClass->name : null,
                'section_name' => $section ? $section->name : null,
            ];
        }

        // الشعبة مشغولة بنفس الخانة؟
        $sectionBusy = $this->slotQuery($day, $period, $ignoreId)
            ->where('section_id', $sectionId)
            ->first();

        if ($sectionBusy) {
            $teacher = $sectionBusy->teacher;

            $found[] = [
                'type' => 'section_busy',
                'message' => 'الشعبة لديها حصة أخرى في نفس اليوم ونفس الحصة',
                'weekly_schedule_id' => $sectionBusy->id,
                'subject' => $sectionBusy->teacherAssignment ? $sectionBusy->teacherAssignment->subject->name : null,
                'teacher_name' => $teacher ? $teacher->user->user_name : null,
            ];
        }

        return $found;
    }

    /** استعلام أساسي لخانة معيّنة (يوم + رقم حصة)، مع تجاهل حصة إذا لزم */
    private function slotQuery(string $day, int $period, ?int $ignoreId)
    {
        $query = WeeklySchedule::where('day_of_week', $day)
            ->where('period_number', $period)
            ->with('teacher.user:id,user_name', 'teacherAssignment.subject:id,name', 'section.schoolClass');

        // وقت نقل حصة، ما بدنا نعتبرها متعارضة مع حالها
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query;
    }

    /** بيرجّع تعريف الحصة إذا كانت حصة درس فعلية (مش استراحة ولا رقم غلط) */
    private function classPeriod(int $number): ?array
    {
        $period = config('school.periods.'.$number);

        if (!$period) {
            return null;
        }

        if ($period['type'] !== 'class') {
            return null;
        }

        return $period;
    }

    /** مجموع خانات الدروس بالأسبوع = حصص الدرس باليوم × أيام الدوام */
    private function classPeriodCount(): int
    {
        $classPeriods = 0;

        foreach (config('school.periods') as $period) {
            if ($period['type'] === 'class') {
                $classPeriods++;
            }
        }

        return $classPeriods * count(config('school.school_days'));
    }

    /** تعريف الحصص للواجهة */
    private function periodList(): array
    {
        $periods = [];

        foreach (config('school.periods') as $number => $period) {
            $periods[] = [
                'period_number' => $number,
                'start_time' => $period['start'],
                'end_time' => $period['end'],
                'type' => $period['type'],
            ];
        }

        return $periods;
    }

    private function invalidPeriod(int $number)
    {
        return response()->json([
            'success' => false,
            'message' => "رقم الحصة {$number} غير صالح أو أنه وقت استراحة",
        ], 422);
    }

}
