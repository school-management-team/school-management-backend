<?php

namespace App\Http\Controllers;

use App\Models\LessonSubstitution;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\WeeklySchedule;
use App\Services\SchoolCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubstitutionController extends Controller
{
    protected $calendarService;

    public function __construct(SchoolCalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    /**
     * الحصص اليتيمة: كل معلم مسجّل غائب بهذا التاريخ + حصصه + هل تم تعويضها.
     * GET /supervisor/substitutions/absent-lessons?date=2026-08-17
     */
    public function absentLessons(Request $request)
    {
        $validated = $request->validate(['date' => 'sometimes|date_format:Y-m-d']);
        $date = $validated['date'] ?? now()->toDateString();

        // عطلة أسبوعية أو رسمية = ما في حصص أصلاً
        $reason = $this->calendarService->nonSchoolDayReason($date);

        if ($reason) {
            $data = [
                'date' => $date,
                'absent_teachers' => [],
                'uncovered_count' => 0,
            ];

            return response()->json([
                'success' => true,
                'message' => $reason['message'],
                'data' => array_merge($data, $reason),
            ]);
        }

        $day = $this->dayOfWeek($date);

        $awayTeacherIds = TeacherAttendance::forDate($date)->away()->pluck('teacher_id');

        if ($awayTeacherIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'لا يوجد معلمون غائبون في هذا التاريخ',
                'data' => [
                    'date' => $date,
                    'day_of_week' => $day,
                    'absent_teachers' => [],
                    'absent_teachers_count' => 0,
                    'lessons_count' => 0,
                    'covered_count' => 0,
                    'uncovered_count' => 0,
                ],
            ]);
        }

        $lessons = WeeklySchedule::query()
            ->whereIn('teacher_id', $awayTeacherIds)
            ->where('day_of_week', $day)
            ->where('type', 'class')
            ->with([
                'teacher.user:id,user_name',
                'teacher.subject:id,name',
                'teacherAssignment.subject:id,name',
                'teacherAssignment.section.schoolClass',
            ])
            ->orderBy('period_number')
            ->get();

        $attendances = TeacherAttendance::forDate($date)
            ->whereIn('teacher_id', $awayTeacherIds)
            ->get()
            ->keyBy('teacher_id');

        $substitutions = LessonSubstitution::forDate($date)
            ->active()
            ->whereIn('weekly_schedule_id', $lessons->pluck('id'))
            ->with('substituteTeacher.user:id,user_name')
            ->get()
            ->keyBy('weekly_schedule_id');

        $uncovered = 0;
        $absentTeachers = [];

        // منجمّع حصص كل معلم غايب تحت اسمه
        foreach ($lessons->groupBy('teacher_id') as $teacherId => $teacherLessons) {
            $teacher = $teacherLessons->first()->teacher;
            $attendance = $attendances->get($teacherId);

            $items = [];
            $teacherUncovered = 0;

            foreach ($teacherLessons as $lesson) {
                $substitution = $substitutions->get($lesson->id);

                $substitutionInfo = null;

                if ($substitution) {
                    $substituteTeacher = $substitution->substituteTeacher;

                    $substitutionInfo = [
                        'id' => $substitution->id,
                        'substitute_teacher_id' => $substitution->substitute_teacher_id,
                        'substitute_teacher_name' => $substituteTeacher ? $substituteTeacher->user->user_name : null,
                        'status' => $substitution->status,
                    ];
                } else {
                    $uncovered++;
                    $teacherUncovered++;
                }

                $items[] = [
                    'weekly_schedule_id' => $lesson->id,
                    'period_number' => $lesson->period_number,
                    'start_time' => $lesson->start_time,
                    'end_time' => $lesson->end_time,
                    'subject' => $lesson->subject_name,
                    'class_name' => $lesson->class_name,
                    'section_name' => $lesson->section_name,
                    'is_covered' => $substitution !== null,
                    'substitution' => $substitutionInfo,
                ];
            }

            $absentTeachers[] = [
                'teacher_id' => (int) $teacherId,
                'teacher_name' => $teacher && $teacher->user ? $teacher->user->user_name : null,
                'subject' => $teacher && $teacher->subject ? $teacher->subject->name : null,
                'attendance_status' => $attendance ? $attendance->status : null,
                'excuse' => $attendance ? $attendance->excuse : null,
                'lessons_count' => count($items),
                'uncovered_count' => $teacherUncovered,
                'lessons' => $items,
            ];
        }

        $covered = 0;

        foreach ($absentTeachers as $teacher) {
            $covered += $teacher['lessons_count'] - $teacher['uncovered_count'];
        }

        $message = count($absentTeachers).' معلم غائب، '
            .($uncovered + $covered).' حصة، منها '.$uncovered.' بلا تعويض';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'date' => $date,
                'day_of_week' => $day,
                'absent_teachers' => $absentTeachers,
                'absent_teachers_count' => count($absentTeachers),
                'lessons_count' => $uncovered + $covered,
                'covered_count' => $covered,
                'uncovered_count' => $uncovered,
            ],
        ]);
    }

    /**
     * المعلمون المتاحون لتغطية حصة معيّنة، مرتبين حسب الأنسب.
     * GET /supervisor/substitutions/available-teachers?weekly_schedule_id=12&date=2026-08-17
     */
    public function availableTeachers(Request $request)
    {
        $validated = $request->validate([
            'weekly_schedule_id' => 'required|exists:weekly_schedules,id',
            'date' => 'sometimes|date_format:Y-m-d',
            // same_subject=1 → معلمي نفس المادة فقط. بدونها: الكل، والأنسب أولاً
            'same_subject' => 'sometimes|boolean',
        ]);

        $date = $validated['date'] ?? now()->toDateString();
        $sameSubjectOnly = $request->boolean('same_subject');

        $lesson = WeeklySchedule::with(['teacher.user', 'teacherAssignment.subject', 'teacherAssignment.section.schoolClass'])
            ->findOrFail($validated['weekly_schedule_id']);

        $dateDay = $this->dayOfWeek($date);

        if ($lesson->day_of_week !== $dateDay) {
            /*
             | ما بيكفي نقول "التاريخ غلط" — منقترح أقرب تاريخين يوافقان
             | يوم الحصة، حتى ما يضطر المستخدم يحسبهن بنفسه.
             */
            $nearest = $this->calendarService->nearestDatesFor($lesson->day_of_week, $date);
            $lessonDayLabel = $this->calendarService->dayLabel($lesson->day_of_week);
            $dateDayLabel = $this->calendarService->dayLabel($dateDay);

            return response()->json([
                'success' => false,
                'message' => "الحصة رقم {$lesson->id} مجدولة يوم {$lessonDayLabel}، "
                    ."والتاريخ {$date} يوم {$dateDayLabel}. "
                    ."أقرب {$lessonDayLabel} هو {$nearest['next']} (والسابق {$nearest['previous']})",
                'data' => [
                    'lesson_day' => $lesson->day_of_week,
                    'lesson_day_label' => $lessonDayLabel,
                    'date_day' => $dateDay,
                    'date_day_label' => $dateDayLabel,
                    'date' => $date,
                    // تواريخ جاهزة للاستعمال مباشرة
                    'suggested_dates' => [
                        'next' => $nearest['next'],
                        'previous' => $nearest['previous'],
                    ],
                ],
            ], 422);
        }

        // مادة الحصة نفسها، وإذا ما في تكليف مربوط منرجع لمادة المعلم الغائب
        $targetSubjectId = null;

        if ($lesson->teacherAssignment) {
            $targetSubjectId = $lesson->teacherAssignment->subject_id;
        } elseif ($lesson->teacher) {
            $targetSubjectId = $lesson->teacher->subject_id;
        }

        $targetStageId = $lesson->teacher ? $lesson->teacher->stage_id : null;

        $day = $lesson->day_of_week;
        $period = $lesson->period_number;

        $weekStart = Carbon::parse($date)->startOfWeek(Carbon::SUNDAY)->toDateString();
        $weekEnd = Carbon::parse($date)->startOfWeek(Carbon::SUNDAY)->addDays(4)->toDateString();

        $query = Teacher::with('user:id,user_name', 'subject:id,name', 'stage:id,name')
            ->where('id', '!=', $lesson->teacher_id);

        // شرط 1: مسجّل موجود بالمدرسة اليوم (حاضر أو متأخر)
        $query->whereHas('attendances', function ($attendance) use ($date) {
            $attendance->forDate($date)->atSchool();
        });

        // شرط 2: ما عندو حصة بنفس اليوم ونفس رقم الحصة
        $query->whereDoesntHave('weeklySchedules', function ($schedule) use ($day, $period) {
            $schedule->where('day_of_week', $day)
                ->where('period_number', $period)
                ->where('type', 'class');
        });

        // شرط 3: ما مكلّف أصلاً بتعويض تاني بنفس التوقيت
        $query->whereDoesntHave('substitutions', function ($substitution) use ($date, $period) {
            $substitution->forDate($date)->where('period_number', $period)->active();
        });

        // عدد التعويضات يلي أخدها هالأسبوع — لتوزيع العبء بالعدل
        $query->withCount(['substitutions as substitutions_this_week' => function ($substitution) use ($weekStart, $weekEnd) {
            $substitution->active()->whereBetween('date', [$weekStart, $weekEnd]);
        }]);

        $teachers = $query->get();

        $candidates = [];

        foreach ($teachers as $teacher) {
            $sameSubject = $targetSubjectId !== null && $teacher->subject_id === $targetSubjectId;
            $sameStage = $targetStageId !== null && $teacher->stage_id === $targetStageId;

            // نفس المادة بتساوي نقطتين، ونفس المرحلة نقطة
            $score = 0;

            if ($sameSubject) {
                $score += 2;
            }

            if ($sameStage) {
                $score += 1;
            }

            $candidates[] = [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->user ? $teacher->user->user_name : null,
                'subject_id' => $teacher->subject_id,
                'subject' => $teacher->subject ? $teacher->subject->name : null,
                'stage' => $teacher->stage ? $teacher->stage->name : null,
                'same_subject' => $sameSubject,
                'same_stage' => $sameStage,
                'substitutions_this_week' => $teacher->substitutions_this_week,
                'match_score' => $score,
            ];
        }

        // الأنسب أولاً، وعند التساوي الأقل عبئاً، وبعدها ترتيب أبجدي
        usort($candidates, function ($a, $b) {
            if ($a['match_score'] !== $b['match_score']) {
                return $b['match_score'] - $a['match_score'];
            }

            if ($a['substitutions_this_week'] !== $b['substitutions_this_week']) {
                return $a['substitutions_this_week'] - $b['substitutions_this_week'];
            }

            return strcmp($a['teacher_name'], $b['teacher_name']);
        });

        $sameSubjectCount = 0;

        foreach ($candidates as $candidate) {
            if ($candidate['same_subject']) {
                $sameSubjectCount++;
            }
        }

        // الفلترة الصارمة بتصير بعد الترتيب، حتى نعرف كم واحد استبعدنا
        $allAvailable = count($candidates);

        if ($sameSubjectOnly) {
            $filtered = [];

            foreach ($candidates as $candidate) {
                if ($candidate['same_subject']) {
                    $filtered[] = $candidate;
                }
            }

            $candidates = $filtered;
        }

        if (count($candidates) === 0) {
            if ($sameSubjectOnly && $allAvailable > 0) {
                $message = "لا يوجد معلم متاح لنفس المادة. يوجد {$allAvailable} معلم متاح من مواد أخرى — أعد الطلب بدون same_subject لعرضهم";
            } else {
                $message = $this->noCandidatesReason($date, $lesson);
            }
        } else {
            $message = 'عدد المعلمين المتاحين: '.count($candidates);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'date' => $date,
                'lesson' => [
                    'weekly_schedule_id' => $lesson->id,
                    'period_number' => $lesson->period_number,
                    'day_of_week' => $lesson->day_of_week,
                    'start_time' => $lesson->start_time,
                    'end_time' => $lesson->end_time,
                    'subject' => $lesson->subject_name,
                    'class_name' => $lesson->class_name,
                    'section_name' => $lesson->section_name,
                    'absent_teacher_id' => $lesson->teacher_id,
                    // اسم صاحب الحصة الغايب — مش مرشّح، بس للتوضيح
                    'absent_teacher_name' => $lesson->teacher && $lesson->teacher->user
                        ? $lesson->teacher->user->user_name
                        : null,
                ],
                'available_teachers' => $candidates,
                'total_available' => count($candidates),
                'same_subject_count' => $sameSubjectCount,
                'filtered_by_same_subject' => $sameSubjectOnly,
            ],
        ]);
    }

    /**
     * تعيين معلم بديل لحصة.
     * POST /supervisor/substitutions
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'weekly_schedule_id' => 'required|exists:weekly_schedules,id',
            'substitute_teacher_id' => 'required|exists:teachers,id',
            'date' => 'sometimes|date_format:Y-m-d',
            'note' => 'nullable|string|max:255',
        ]);

        $supervisor = $request->user()->supervisor;

        if (!$supervisor) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد ملف موجّه مرتبط بهذا الحساب',
            ], 403);
        }

        $supervisorId = $supervisor->id;
        $date = $validated['date'] ?? now()->toDateString();

        $reason = $this->calendarService->nonSchoolDayReason($date);

        if ($reason) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعيين بديل: '.$reason['message'],
                'data' => $reason,
            ], 422);
        }

        $lesson = WeeklySchedule::findOrFail($validated['weekly_schedule_id']);
        $substituteId = $validated['substitute_teacher_id'];

        if ($lesson->type !== 'class') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعيين بديل لفترة استراحة أو حصة فارغة',
            ], 422);
        }

        if ($lesson->day_of_week !== $this->dayOfWeek($date)) {
            return response()->json([
                'success' => false,
                'message' => 'التاريخ المحدد لا يوافق يوم هذه الحصة في الجدول',
            ], 422);
        }

        if ($lesson->teacher_id === (int) $substituteId) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعيين المعلم بديلاً عن نفسه',
            ], 422);
        }

        // المعلم الأساسي لازم يكون مسجّل غائب فعلاً بهذا التاريخ
        $absentRecord = TeacherAttendance::forDate($date)->away()
            ->where('teacher_id', $lesson->teacher_id)
            ->first();

        if (!$absentRecord) {
            return response()->json([
                'success' => false,
                'message' => 'المعلم صاحب الحصة غير مسجّل كغائب في هذا التاريخ',
            ], 422);
        }

        return DB::transaction(function () use ($lesson, $substituteId, $date, $supervisorId, $validated) {
            // البديل موجود بالمدرسة
            $atSchool = TeacherAttendance::forDate($date)->atSchool()
                ->where('teacher_id', $substituteId)
                ->exists();

            if (!$atSchool) {
                return response()->json([
                    'success' => false,
                    'message' => 'المعلم البديل غير مسجّل كموجود في المدرسة بهذا التاريخ',
                ], 422);
            }

            // البديل ما عندو حصة بنفس التوقيت
            $hasOwnLesson = WeeklySchedule::where('teacher_id', $substituteId)
                ->where('day_of_week', $lesson->day_of_week)
                ->where('period_number', $lesson->period_number)
                ->where('type', 'class')
                ->exists();

            if ($hasOwnLesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'المعلم البديل لديه حصة في نفس التوقيت',
                ], 422);
            }

            // البديل ما مكلّف بتعويض تاني بنفس التوقيت
            $hasOtherSubstitution = LessonSubstitution::forDate($date)->active()
                ->where('substitute_teacher_id', $substituteId)
                ->where('period_number', $lesson->period_number)
                ->where('weekly_schedule_id', '!=', $lesson->id)
                ->exists();

            if ($hasOtherSubstitution) {
                return response()->json([
                    'success' => false,
                    'message' => 'المعلم البديل مكلّف بتعويض آخر في نفس التوقيت',
                ], 422);
            }

            $substitution = LessonSubstitution::updateOrCreate(
                [
                    'weekly_schedule_id' => $lesson->id,
                    'date' => $date,
                ],
                [
                    'absent_teacher_id' => $lesson->teacher_id,
                    'substitute_teacher_id' => $substituteId,
                    'supervisor_id' => $supervisorId,
                    'day_of_week' => $lesson->day_of_week,
                    'period_number' => $lesson->period_number,
                    'status' => 'assigned',
                    'note' => $validated['note'] ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تعيين المعلم البديل بنجاح',
                'data' => $substitution->load([
                    'absentTeacher.user:id,user_name',
                    'substituteTeacher.user:id,user_name',
                    'weeklySchedule.teacherAssignment.subject',
                    'weeklySchedule.teacherAssignment.section.schoolClass',
                ]),
            ], 201);
        });
    }

    /**
     * قائمة التعويضات بتاريخ معيّن.
     * GET /supervisor/substitutions?date=2026-08-17
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => 'sometimes|date_format:Y-m-d',
            'status' => 'sometimes|in:assigned,completed,cancelled',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $query = LessonSubstitution::forDate($date)->with(
            'absentTeacher.user:id,user_name',
            'substituteTeacher.user:id,user_name',
            'weeklySchedule.teacherAssignment.subject',
            'weeklySchedule.teacherAssignment.section.schoolClass'
        );

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $found = $query->orderBy('period_number')->get();

        $substitutions = [];

        foreach ($found as $substitution) {
            $lesson = $substitution->weeklySchedule;
            $absent = $substitution->absentTeacher;
            $substitute = $substitution->substituteTeacher;

            $substitutions[] = [
                'id' => $substitution->id,
                'period_number' => $substitution->period_number,
                'subject' => $lesson ? $lesson->subject_name : null,
                'class_name' => $lesson ? $lesson->class_name : null,
                'section_name' => $lesson ? $lesson->section_name : null,
                'absent_teacher' => $absent ? $absent->user->user_name : null,
                'substitute_teacher' => $substitute ? $substitute->user->user_name : null,
                'status' => $substitution->status,
                'note' => $substitution->note,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'substitutions' => $substitutions,
                'total' => count($substitutions),
            ],
        ]);
    }

    /**
     * تحديث حالة التعويض (تم التنفيذ / إلغاء).
     * PATCH /supervisor/substitutions/{substitution}/status
     */
    public function updateStatus(Request $request, LessonSubstitution $substitution)
    {
        $validated = $request->validate([
            'status' => 'required|in:assigned,completed,cancelled',
            'note' => 'nullable|string|max:255',
        ]);

        $substitution->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة التعويض بنجاح',
            'data' => $substitution->fresh([
                'absentTeacher.user:id,user_name',
                'substituteTeacher.user:id,user_name',
            ]),
        ]);
    }

    /**
     * ليش ما طلع ولا مرشّح؟ الشروط تلاتة، ومنقول أي واحد فيهن قطع الطريق.
     * أكتر سبب شائع: حضور المعلمين ما انرصد لهذا اليوم أصلاً.
     */
    private function noCandidatesReason(string $date, WeeklySchedule $lesson): string
    {
        $totalTeachers = Teacher::where('id', '!=', $lesson->teacher_id)->count();

        if ($totalTeachers === 0) {
            return 'لا يوجد معلمون آخرون في النظام';
        }

        $recorded = TeacherAttendance::forDate($date)
            ->where('teacher_id', '!=', $lesson->teacher_id)
            ->count();

        if ($recorded === 0) {
            return 'لم يُسجَّل حضور المعلمين في هذا التاريخ بعد، فلا يمكن معرفة من هو موجود في المدرسة';
        }

        $atSchool = TeacherAttendance::forDate($date)->atSchool()
            ->where('teacher_id', '!=', $lesson->teacher_id)
            ->count();

        if ($atSchool === 0) {
            return 'جميع المعلمين المسجّلين في هذا التاريخ غير موجودين في المدرسة';
        }

        return "جميع المعلمين الموجودين ({$atSchool}) مشغولون في الحصة {$lesson->period_number}";
    }

    /** تحويل التاريخ ليوم مدرسي، أو null إذا كان جمعة/سبت */
    private function dayOfWeek(string $date): ?string
    {
        return $this->calendarService->schoolDayOf($date);
    }
}
