<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Services\ReportCardService;
use App\Services\SchoolCalendarService;
use App\Services\WeeklyScheduleService;
use Illuminate\Http\Request;

/**
 * واجهة ولي الأمر — قراءة فقط.
 *
 * كل دالة بتبدأ بـ findChild() يلي بيتأكد إنو الطالب فعلاً ابن هذا الولي.
 * بدون هالفحص أي ولي أمر بيقدر يغيّر الـ id بالرابط ويشوف سجل أي طالب.
 */
class GuardianController extends Controller
{
    protected $reportCardService;
    protected $weeklyScheduleService;
    protected $calendarService;

    public function __construct(
        ReportCardService $reportCardService,
        WeeklyScheduleService $weeklyScheduleService,
        SchoolCalendarService $calendarService
    ) {
        $this->reportCardService = $reportCardService;
        $this->weeklyScheduleService = $weeklyScheduleService;
        $this->calendarService = $calendarService;
    }

    // أولاد ولي الأمر
    public function children(Request $request)
    {
        $guardian = $request->user()->guardian;

        if (!$guardian) {
            return $this->notAGuardian();
        }

        $students = $guardian->students()
            ->with('user:id,user_name', 'section.schoolClass')
            ->get();

        $children = [];

        foreach ($students as $student) {
            $children[] = $this->studentInfo($student);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'children' => $children,
                'total' => count($children),
            ],
        ]);
    }

    /**
     * سجل حضور الابن: أيام الغياب والتأخر والخروج المبكر.
     * GET /guardian/children/{student}/attendance?from=&to=&status=&only_concerns=1
     */
    public function attendance(Request $request, Student $student)
    {
        $statuses = config('school.attendance_statuses.student');

        $request->validate([
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'status' => 'sometimes|in:'.implode(',', $statuses),
            'only_concerns' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $child = $this->findChild($request, $student);

        if (!$child) {
            return $this->notMyChild();
        }

        $query = Attendance::where('student_id', $child->id);

        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->to);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // only_concerns: كل شي ما عدا الحضور الكامل
        if ($request->boolean('only_concerns')) {
            $query->concerns();
        }

        $records = $query->orderByDesc('date')->paginate($request->per_page ?? 20);

        $rows = [];

        foreach ($records as $record) {
            $rows[] = [
                'date' => $record->date->toDateString(),
                'status' => $record->status,
                'excuse' => $record->excuse,
                'left_at' => $record->left_at,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $this->studentInfo($child),
                'records' => [
                    'data' => $rows,
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                ],
            ],
        ]);
    }

    /**
     * ملخّص الحضور: عدد كل حالة ونسبة الحضور.
     * GET /guardian/children/{student}/attendance/summary?from=&to=
     */
    public function attendanceSummary(Request $request, Student $student)
    {
        $request->validate([
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from',
        ]);

        $child = $this->findChild($request, $student);

        if (!$child) {
            return $this->notMyChild();
        }

        $query = Attendance::where('student_id', $child->id);

        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->to);
        }

        $records = $query->get();

        // عداد لكل حالة
        $counts = [];

        foreach (config('school.attendance_statuses.student') as $status) {
            $counts[$status] = $records->where('status', $status)->count();
        }

        $total = $records->count();

        // الحاضر والمتأخر والمغادر مبكراً كلهم حضروا فعلياً للمدرسة
        $rate = null;

        if ($total > 0) {
            $attended = $counts['present'] + $counts['late'] + $counts['early_leave'];
            $rate = round($attended / $total * 100, 1);
        }

        $lastAbsence = $records->where('status', 'absent')->sortByDesc('date')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $this->studentInfo($child),
                'from' => $request->from,
                'to' => $request->to,
                'recorded_days' => $total,
                'counts' => $counts,
                'attendance_rate' => $rate,
                'last_absence' => $lastAbsence ? $lastAbsence->date->toDateString() : null,
            ],
        ]);
    }

    /**
     * علامات الابن — المعتمدة فقط.
     * GET /guardian/children/{student}/grades?semester=1
     */
    public function grades(Request $request, Student $student)
    {
        $request->validate([
            'semester' => 'sometimes|integer|in:'.implode(',', config('school.semesters')),
        ]);

        $child = $this->findChild($request, $student);

        if (!$child) {
            return $this->notMyChild();
        }

        if (!$child->section_id) {
            return response()->json([
                'success' => false,
                'message' => 'الطالب غير مسجّل في أي شعبة، لا توجد علامات لعرضها',
            ], 422);
        }

        // true = إخفاء قيم المواد غير المعتمدة عن ولي الأمر
        if ($request->filled('semester')) {
            $semesters = [$this->reportCardService->forStudent($child, (int) $request->semester, true)];
        } else {
            $semesters = $this->reportCardService->forStudentAllSemesters($child, true);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $this->studentInfo($child),
                'grade_components' => $this->gradeComponents(),
                'semesters' => $semesters,
                'note' => 'تظهر علامات المواد بعد اعتمادها فقط',
            ],
        ]);
    }

    /**
     * الرسوم الدراسية المترتّبة على الابن: إجمالي القسط والمسدّد والمتبقي.
     * GET /guardian/children/{student}/fees?academic_year=2026-2027
     */
    public function fees(Request $request, Student $student)
    {
        $request->validate([
            'academic_year' => 'sometimes|string|regex:/^\d{4}-\d{4}$/',
        ], ['academic_year.regex' => 'صيغة السنة الدراسية يجب أن تكون سنتين متتاليتين مثل 2026-2027']);

        $child = $this->findChild($request, $student);

        if (!$child) {
            return $this->notMyChild();
        }

        $query = $child->fees()->with('payments');

        if ($request->filled('academic_year')) {
            $query->forYear($request->academic_year);
        }

        $studentFees = $query->orderByDesc('academic_year')->get();

        $fees = [];
        $totalNet = 0;
        $totalPaid = 0;
        $totalRemaining = 0;

        foreach ($studentFees as $fee) {
            // سجل الدفعات حتى يقدر يطابق إيصالاته
            $payments = [];

            foreach ($fee->payments->sortByDesc('paid_at') as $payment) {
                $payments[] = [
                    'amount' => (float) $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'method' => $payment->method,
                    'receipt_number' => $payment->receipt_number,
                ];
            }

            $fees[] = [
                'academic_year' => $fee->academic_year,
                'total_amount' => (float) $fee->total_amount,
                'discount' => (float) $fee->discount,
                'net_amount' => $fee->net_amount,
                'paid_amount' => $fee->paid_amount,
                'remaining_amount' => $fee->remaining_amount,
                'is_settled' => $fee->is_settled,
                'note' => $fee->note,
                'payments' => $payments,
            ];

            $totalNet += $fee->net_amount;
            $totalPaid += $fee->paid_amount;
            $totalRemaining += $fee->remaining_amount;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $this->studentInfo($child),
                'fees' => $fees,
                // إجمالي كل السنوات، يفيد لما يكون في متأخرات من سنة سابقة
                'totals' => [
                    'net_amount' => round($totalNet, 2),
                    'paid_amount' => round($totalPaid, 2),
                    'remaining_amount' => round($totalRemaining, 2),
                ],
            ],
        ]);
    }

    /**
     * الجدول الأسبوعي لشعبة الابن.
     * مع date بيرجّع حصص ذاك اليوم فقط، ومبيّن إذا في معلم بديل.
     * GET /guardian/children/{student}/schedule?date=2026-08-17
     */
    public function schedule(Request $request, Student $student)
    {
        $request->validate(['date' => 'sometimes|date_format:Y-m-d']);

        $child = $this->findChild($request, $student);

        if (!$child) {
            return $this->notMyChild();
        }

        if (!$child->section_id) {
            return response()->json([
                'success' => false,
                'message' => 'الطالب غير مسجّل في أي شعبة، لا يوجد جدول لعرضه',
            ], 422);
        }

        // بدون تاريخ: الأسبوع كامل
        if (!$request->filled('date')) {
            $week = $this->weeklyScheduleService->sectionWeek($child->section_id);

            return response()->json([
                'success' => true,
                'data' => [
                    'student' => $this->studentInfo($child),
                    'periods' => $this->periods(),
                    'schedule' => $week['grid'],
                ],
            ]);
        }

        $date = $request->date;
        $reason = $this->calendarService->nonSchoolDayReason($date);

        // جمعة/سبت أو عطلة رسمية = ما في حصص
        if ($reason) {
            $data = [
                'student' => $this->studentInfo($child),
                'date' => $date,
                'is_school_day' => false,
                'lessons' => [],
            ];

            return response()->json([
                'success' => true,
                'data' => array_merge($data, $reason),
            ]);
        }

        $dayOfWeek = $this->calendarService->schoolDayOf($date);

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $this->studentInfo($child),
                'date' => $date,
                'is_school_day' => true,
                'day_of_week' => $dayOfWeek,
                'lessons' => $this->weeklyScheduleService->sectionDay($child->section_id, $dayOfWeek, $date),
            ],
        ]);
    }

    // ==================== أدوات داخلية ====================

    /**
     * بيرجّع الطالب إذا كان فعلاً ابن هذا الولي، وإلا null.
     * هذا هو الفحص الأمني الأساسي بكل نقاط ولي الأمر.
     */
    private function findChild(Request $request, Student $student)
    {
        $guardian = $request->user()->guardian;

        if (!$guardian) {
            return null;
        }

        $isMyChild = $guardian->students()->where('students.id', $student->id)->exists();

        if (!$isMyChild) {
            return null;
        }

        return $student;
    }

    private function studentInfo(Student $student): array
    {
        $student->loadMissing('user:id,user_name', 'section.schoolClass');

        $className = null;

        if ($student->section && $student->section->schoolClass) {
            $className = $student->section->schoolClass->name;
        }

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->user ? $student->user->user_name : null,
            'class_name' => $className,
            'section_name' => $student->section ? $student->section->name : null,
        ];
    }

    private function periods(): array
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

    private function gradeComponents(): array
    {
        $components = [];

        foreach (config('school.grade_components') as $type => $component) {
            $components[] = [
                'type' => $type,
                'label' => $component['label'],
                'weight' => $component['weight'],
            ];
        }

        return $components;
    }

    private function notMyChild()
    {
        return response()->json([
            'success' => false,
            'message' => 'لا تملك صلاحية الاطلاع على سجل هذا الطالب',
        ], 403);
    }

    private function notAGuardian()
    {
        return response()->json([
            'success' => false,
            'message' => 'لا يوجد ملف ولي أمر مرتبط بهذا الحساب',
        ], 403);
    }
}
