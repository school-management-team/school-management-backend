<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\LessonSubstitution;
use App\Models\Section;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Services\SchoolCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupervisorAttendanceController extends Controller
{
    const ATTENDANCE_MESSAGES = [
        'records.*.student_id.distinct' => 'الطالب مكرر أكثر من مرة في نفس الطلب',
        'records.*.teacher_id.distinct' => 'المعلم مكرر أكثر من مرة في نفس الطلب',
    ];

    protected $calendarService;

    public function __construct(SchoolCalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    // ==================== حضور الطلاب ====================

    /** كشف شعبة بتاريخ معيّن: كل الطلاب مع حالتهم المسجّلة (إن وجدت) */
    public function studentSheet(Request $request)
    {
        $validated = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'date' => 'sometimes|date_format:Y-m-d',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $section = Section::with('schoolClass')->findOrFail($validated['section_id']);

        $records = Attendance::where('section_id', $section->id)
            ->forDate($date)
            ->get()
            ->keyBy('student_id');

        $sectionStudents = $section->students()
            ->with('user:id,user_name')
            ->orderBy('student_number')
            ->get();

        $students = [];

        foreach ($sectionStudents as $student) {
            // إذا ما في تسجيل لهذا الطالب اليوم، الحقول بترجع فاضية
            $record = $records->get($student->id);

            $students[] = [
                'student_id' => $student->id,
                'student_number' => $student->student_number,
                'student_name' => $student->user ? $student->user->user_name : null,
                'status' => $record ? $record->status : null,
                'excuse' => $record ? $record->excuse : null,
                'left_at' => $record ? $record->left_at : null,
                'recorded' => $record !== null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $this->withDayContext($date) + [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'class_name' => $section->schoolClass ? $section->schoolClass->name : null,
                ],
                'students' => $students,
                'recorded_count' => $records->count(),
                'total_count' => count($students),
            ],
        ]);
    }

    /** تسجيل حضور الطلاب دفعة واحدة (حاضر / غائب / متأخر / غياب بعذر) */
    public function storeStudentAttendance(Request $request)
    {
        $validated = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'date' => 'sometimes|date_format:Y-m-d',
            'records' => 'required|array|min:1',
            'records.*.student_id' => 'required|exists:students,id|distinct',
            'records.*.status' => 'required|in:'.implode(',', config('school.attendance_statuses.student')),
            'records.*.excuse' => 'nullable|string|max:255',
            'records.*.left_at' => 'nullable|date_format:H:i',
        ], self::ATTENDANCE_MESSAGES);

        $supervisorId = $this->supervisorId($request);

        if (!$supervisorId) {
            return $this->notASupervisor();
        }

        $date = $validated['date'] ?? now()->toDateString();

        if ($blocked = $this->blockedDay($date)) {
            return $blocked;
        }

        $sectionId = $validated['section_id'];

        // كل طالب لازم يكون فعلاً بهالشعبة
        $sectionStudentIds = Section::findOrFail($sectionId)->students()->pluck('id')->all();

        $foreign = [];

        foreach ($validated['records'] as $record) {
            if (!in_array($record['student_id'], $sectionStudentIds)) {
                $foreign[] = $record['student_id'];
            }
        }

        if (count($foreign) > 0) {
            return response()->json([
                'success' => false,
                'message' => 'بعض الطلاب لا ينتمون لهذه الشعبة',
                'data' => ['student_ids' => $foreign],
            ], 422);
        }

        $conflict = $this->checkFieldsMatchStatus($validated['records'], 'student');

        if ($conflict) {
            return $conflict;
        }

        $saved = DB::transaction(function () use ($validated, $date, $sectionId, $supervisorId) {
            $rows = [];

            foreach ($validated['records'] as $record) {
                $rows[] = Attendance::updateOrCreate(
                    [
                        'student_id' => $record['student_id'],
                        'date' => $date,
                    ],
                    [
                        'section_id' => $sectionId,
                        'supervisor_id' => $supervisorId,
                        'status' => $record['status'],
                        'excuse' => $record['excuse'] ?? null,
                        'left_at' => $record['left_at'] ?? null,
                    ]
                );
            }

            return $rows;
        });

        $createdCount = 0;

        foreach ($saved as $record) {
            if ($record->wasRecentlyCreated) {
                $createdCount++;
            }
        }

        $updatedCount = count($saved) - $createdCount;
        $message = 'سجّلنا '.$createdCount.' حضور جديد';

        if ($updatedCount > 0) {
            $message .= ' وحدّثنا '.$updatedCount.' كان مسجّل مسبقاً';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'date' => $date,
                'saved_count' => count($saved),
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'records' => $saved,
            ],
        ], $createdCount > 0 ? 201 : 200);
    }

    // ==================== حضور المعلمين ====================

    /** كشف المعلمين بتاريخ معيّن مع حالتهم المسجّلة */
    public function teacherSheet(Request $request)
    {
        $validated = $request->validate([
            'date' => 'sometimes|date_format:Y-m-d',
            'stage_id' => 'sometimes|exists:stages,id',
            'subject_id' => 'sometimes|exists:subjects,id',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $records = TeacherAttendance::forDate($date)->get()->keyBy('teacher_id');

        $query = Teacher::with('user:id,user_name', 'subject:id,name', 'stage:id,name');

        if ($request->filled('stage_id')) {
            $query->where('stage_id', $request->stage_id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        $allTeachers = $query->get();

        $teachers = [];

        // عدادات الملخّص، منزيدها ونحنا ماشيين على المعلمين
        $summary = [
            'total' => 0,
            'recorded' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'excused' => 0,
        ];

        foreach ($allTeachers as $teacher) {
            $record = $records->get($teacher->id);
            $status = $record ? $record->status : null;

            $teachers[] = [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->user ? $teacher->user->user_name : null,
                'subject' => $teacher->subject ? $teacher->subject->name : null,
                'stage' => $teacher->stage ? $teacher->stage->name : null,
                'status' => $status,
                'excuse' => $record ? $record->excuse : null,
                'check_in_time' => $record ? $record->check_in_time : null,
                'recorded' => $record !== null,
            ];

            $summary['total']++;

            if ($record) {
                $summary['recorded']++;
                $summary[$status]++;
            }
        }

        // ترتيب أبجدي بالاسم
        usort($teachers, function ($a, $b) {
            return strcmp($a['teacher_name'], $b['teacher_name']);
        });

        if (count($teachers) === 0) {
            $message = $this->noTeachersReason($request);
        } else {
            $message = 'عدد المعلمين: '.count($teachers);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->withDayContext($date) + [
                'teachers' => $teachers,
                'summary' => $summary,
            ],
        ]);
    }

    /** تسجيل حضور المعلمين دفعة واحدة */
    public function storeTeacherAttendance(Request $request)
    {
        $validated = $request->validate([
            'date' => 'sometimes|date_format:Y-m-d',
            'records' => 'required|array|min:1',
            'records.*.teacher_id' => 'required|exists:teachers,id|distinct',
            'records.*.status' => 'required|in:'.implode(',', config('school.attendance_statuses.teacher')),
            'records.*.excuse' => 'nullable|string|max:255',
            'records.*.check_in_time' => 'nullable|date_format:H:i',
        ], self::ATTENDANCE_MESSAGES);

        $supervisorId = $this->supervisorId($request);

        if (!$supervisorId) {
            return $this->notASupervisor();
        }

        $date = $validated['date'] ?? now()->toDateString();

        if ($blocked = $this->blockedDay($date)) {
            return $blocked;
        }

        // حقول ما بتناسب الحالة المرسلة — تناقض لازم ينرفض مش ينحفظ
        $conflict = $this->checkFieldsMatchStatus($validated['records'], 'teacher');

        if ($conflict) {
            return $conflict;
        }

        $saved = DB::transaction(function () use ($validated, $date, $supervisorId) {
            $rows = [];

            foreach ($validated['records'] as $record) {
                $rows[] = TeacherAttendance::updateOrCreate(
                    [
                        'teacher_id' => $record['teacher_id'],
                        'date' => $date,
                    ],
                    [
                        'supervisor_id' => $supervisorId,
                        'status' => $record['status'],
                        'excuse' => $record['excuse'] ?? null,
                        'check_in_time' => $record['check_in_time'] ?? null,
                    ]
                );
            }

            return $rows;
        });

        $awayCount = 0;
        $createdCount = 0;
        $backAtSchool = [];

        foreach ($saved as $record) {
            if ($record->status === 'absent' || $record->status === 'excused') {
                $awayCount++;
            } else {
                $backAtSchool[] = $record->teacher_id;
            }

            if ($record->wasRecentlyCreated) {
                $createdCount++;
            }
        }

        $cancelledSubstitutions = 0;

        if ($backAtSchool !== []) {
            $cancelledSubstitutions = LessonSubstitution::forDate($date)
                ->active()
                ->whereIn('absent_teacher_id', $backAtSchool)
                ->update([
                    'status' => 'cancelled',
                    'note' => 'أُلغي تلقائياً: سُجّل المعلم حاضراً في هذا التاريخ',
                ]);
        }

        $updatedCount = count($saved) - $createdCount;
        $message = 'سجّلنا '.$createdCount.' حضور جديد';

        if ($updatedCount > 0) {
            $message .= ' وحدّثنا '.$updatedCount.' كان مسجّل مسبقاً';
        }

        if ($cancelledSubstitutions > 0) {
            $message .= ' وألغينا '.$cancelledSubstitutions.' تعويض لمعلمين صاروا حاضرين';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'date' => $date,
                'saved_count' => count($saved),
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'away_count' => $awayCount,
                'cancelled_substitutions' => $cancelledSubstitutions,
                'records' => $saved,
            ],
        ], $createdCount > 0 ? 201 : 200);
    }

    // ==================== أدوات مشتركة ====================

    private function supervisorId(Request $request): ?int
    {
        $supervisor = $request->user()->supervisor;

        if (!$supervisor) {
            return null;
        }

        return $supervisor->id;
    }

    /**
     * ليش ما طلع ولا معلم؟ منوضّح السبب بدل ما نرجّع قائمة فاضية بلا تفسير.
     * أهم حالة: المادة أصلاً مش مقررة بهالمرحلة (حسب جدول stage_subject).
     */
    private function noTeachersReason(Request $request): string
    {
        $subject = $request->filled('subject_id') ? Subject::find($request->subject_id) : null;
        $stage = $request->filled('stage_id') ? Stage::find($request->stage_id) : null;

        if ($subject && $stage) {
            $linked = $stage->subjects()->where('subjects.id', $subject->id)->exists();

            if (!$linked) {
                return "مادة {$subject->name} غير مقررة في مرحلة {$stage->name}";
            }

            return "لا يوجد معلمون لمادة {$subject->name} في مرحلة {$stage->name}";
        }

        if ($subject) {
            return "لا يوجد معلمون لمادة {$subject->name}";
        }

        if ($stage) {
            return "لا يوجد معلمون في مرحلة {$stage->name}";
        }

        return 'لا يوجد معلمون مسجّلون في النظام';
    }

    /**
     * الحقول لازم تناسب الحالة:
     *   check_in_time  ← وقت الوصول، بس للحاضر والمتأخر
     *   left_at        ← وقت المغادرة، بس للخروج المبكر
     *   excuse         ← العذر، ما إلو معنى مع الحضور الكامل
     */
    private function checkFieldsMatchStatus(array $records, string $role)
    {
        $rules = [
            'check_in_time' => ['allowed' => ['present', 'late'], 'label' => 'وقت الوصول'],
            'left_at' => ['allowed' => ['early_leave'], 'label' => 'وقت المغادرة'],
            'excuse' => ['allowed' => ['absent', 'excused', 'late', 'early_leave'], 'label' => 'العذر'],
        ];

        foreach ($records as $index => $record) {
            $status = $record['status'];

            foreach ($rules as $field => $rule) {
                if (!isset($record[$field]) || $record[$field] === null) {
                    continue;
                }

                if (in_array($status, $rule['allowed'])) {
                    continue;
                }

                return response()->json([
                    'success' => false,
                    'message' => "لا يمكن إرسال {$rule['label']} مع الحالة \"{$status}\"",
                    'data' => [
                        'index' => $index,
                        'field' => $field,
                        'status' => $status,
                        'allowed_with' => $rule['allowed'],
                    ],
                ], 422);
            }
        }

        return null;
    }

    /**
     * معلومات اليوم للردود: هل هو يوم دوام، وإذا لأ فشو السبب.
     * القراءة مسموحة بأيام العطل (ممكن تكون في سجلات قديمة)، بس لازم
     * يبيّن بوضوح إنه مش يوم دوام حتى ما يتفاجأ المستخدم.
     */
    private function withDayContext(string $date): array
    {
        $reason = $this->calendarService->nonSchoolDayReason($date);

        $context = [
            'date' => $date,
            'is_school_day' => $reason === null,
        ];

        if ($reason) {
            $context = array_merge($context, $reason);
        }

        return $context;
    }

    /** ما بينرصد حضور بعطلة رسمية ولا بيوم عطلة أسبوعية ولا بيوم لسا ما إجا */
    private function blockedDay(string $date)
    {
        $reason = $this->calendarService->nonSchoolDayReason($date);

        if (!$reason && $date > now()->toDateString()) {
            $reason = [
                'reason' => 'future',
                'message' => 'هذا التاريخ لم يأتِ بعد',
            ];
        }

        if (!$reason) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'لا يمكن تسجيل الحضور: '.$reason['message'],
            'data' => $reason,
        ], 422);
    }

    private function notASupervisor()
    {
        return response()->json([
            'success' => false,
            'message' => 'لا يوجد ملف موجّه مرتبط بهذا الحساب',
        ], 403);
    }
}
