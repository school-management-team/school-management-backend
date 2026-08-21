<?php
namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Student;
use App\Services\AnnouncementService;
use App\Services\AssignmentService;
use App\Services\GradeService;
use App\Services\WeeklyScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    public function __construct(
        protected AnnouncementService $announcementService,
        protected AssignmentService $assignmentService,
        protected GradeService $gradeService,
        protected WeeklyScheduleService $weeklyScheduleService,
    ) {}

    // ==================== واجهة "أهلاً بك" (الرئيسية) ====================

public function dashboard(Request $request)
{
    $student = $request->user()->student;
    $todaySummary = $this->weeklyScheduleService->todaySummaryForStudent($student);

    $nextClass = $todaySummary['next_class'];
    if ($nextClass) {
        $nextClass->load(['teacherAssignment.subject', 'teacherAssignment.section.schoolClass']);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'attendance_rate' => $this->attendanceRate($student),
            'average_grade_100' => $this->gradeService->averageForStudent($student),
            'next_class' => $todaySummary['next_class'],
            'today_assignments' => $this->assignmentService->todayForStudent($student),
        ],
    ]);
}
public function remainingHoursToday(Request $request)
{
    $summary = $this->weeklyScheduleService->todaySummaryForStudent($request->user()->student);

    return response()->json([
        'success' => true,
        'data' => ['remaining_hours_today' => $summary['remaining_hours_today']],
    ]);
}

    // نسبة الحضور (present / إجمالي السجلات المسجّلة)
    private function attendanceRate($student): ?float
    {
        $total = Attendance::where('student_id', $student->id)->count();

        if ($total === 0) {
            return null;
        }

        $present = Attendance::where('student_id', $student->id)->where('status', 'present')->count();

        return round(($present / $total) * 100, 1);
    }

    // ==================== واجهة الجدول الأسبوعي ====================

    public function dailySchedule(Request $request)
    {
        $validator = Validator::make($request->all(), ['date' => 'nullable|date']);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $date = $request->date ?? now()->toDateString();
        $dayOfWeek = strtolower(\Carbon\Carbon::parse($date)->englishDayOfWeek);

        $schedule = $this->weeklyScheduleService->dayForStudent($request->user()->student, $dayOfWeek, $date);

        return response()->json(['success' => true, 'data' => $schedule]);
    }

    public function weeklySchedule(Request $request)
    {
        $schedule = $this->weeklyScheduleService->weekForStudent($request->user()->student);

        return response()->json(['success' => true, 'data' => $schedule]);
    }

    // ==================== واجهة الإعلانات (قراءة فقط، نفس منطق المعلم) ====================

 // عرض/فلترة الإعلانات (تبويبات: الكل، إداري، أكاديمي، نشاطات)
public function announcements(Request $request)
{
    $filters = $request->only(['type', 'per_page']);

    if ($request->has('is_important')) {
        $filters['is_important'] = $request->boolean('is_important');
    }

    $announcements = $this->announcementService->list($filters);

    return response()->json(['success' => true, 'data' => $announcements]);
}

// الفعاليات القادمة (مع فلترة اختيارية بالنوع)
public function upcomingAnnouncements(Request $request)
{
    $announcements = $this->announcementService->upcoming(
        $request->limit ?? 5,
        $request->type ?? null
    );

    return response()->json(['success' => true, 'data' => $announcements]);
}

// "تواريخ مهمة" — أحدث تاريخين هامّين لم ينتهيا
public function importantAnnouncementDates(Request $request)
{
    $dates = $this->announcementService->upcomingImportant($request->limit ?? 2);

    return response()->json(['success' => true, 'data' => $dates]);
}

// نقاط التقويم (شهر + سنة، بدون يوم)
public function announcementCalendarDots(Request $request)
{
    $validator = Validator::make($request->all(), [
        'year' => 'required|integer',
        'month' => 'required|integer|min:1|max:12',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $dates = $this->announcementService->calendarDots($request->year, $request->month);

    return response()->json(['success' => true, 'data' => $dates]);
}

// كل إعلانات شهر كامل (العرض الافتراضي)
public function announcementsForMonth(Request $request)
{
    $validator = Validator::make($request->all(), [
        'year' => 'required|integer',
        'month' => 'required|integer|min:1|max:12',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $announcements = $this->announcementService->forMonth($request->year, $request->month);

    return response()->json(['success' => true, 'data' => $announcements]);
}

// كل إعلانات يوم محدد (لما المستخدم يضغط على يوم بالتقويم)
public function announcementsForDay(Request $request)
{
    $validator = Validator::make($request->all(), [
        'year' => 'required|integer',
        'month' => 'required|integer|min:1|max:12',
        'day' => 'required|integer|min:1|max:31',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $announcements = $this->announcementService->forDay($request->year, $request->month, $request->day);

    return response()->json(['success' => true, 'data' => $announcements]);
}

// تفاصيل إعلان واحد (زر "قراءة المزيد")
public function showAnnouncement($id)
{
    $announcement = $this->announcementService->find($id);

    return response()->json(['success' => true, 'data' => $announcement]);
}
// ==================== واجهة "إنجازك اليوم" ====================

public function assignmentsWithStatus(Request $request)
{
    $validator = Validator::make($request->all(), [
        'status' => 'nullable|in:in_progress,completed',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $assignments = $this->assignmentService->listForStudentWithStatus(
        $request->user()->student,
        $request->only(['status'])
    );

    return response()->json(['success' => true, 'data' => $assignments]);
}

public function assignmentProgress(Request $request)
{
    $progress = $this->assignmentService->todayProgressForStudent($request->user()->student);

    return response()->json(['success' => true, 'data' => $progress]);
}

public function completeAssignment(Request $request, int $assignmentId)
{
    $student = $request->user()->student;

    $assignment = \App\Models\Assignment::with('teacherAssignment')->find($assignmentId);

    if (!$assignment) {
        return response()->json(['success' => false, 'message' => 'الواجب غير موجود'], 404);
    }

    if (!$student->section_id
        || $assignment->teacherAssignment?->section_id !== $student->section_id) {
        return response()->json([
            'success' => false,
            'message' => 'هذا الواجب ليس لشعبتك',
        ], 403);
    }

    $status = $this->assignmentService->markCompleted($student, $assignmentId);

    return response()->json(['success' => true, 'message' => 'تم إنهاء المهمة', 'data' => $status]);
}

// ==================== واجهة "تحليل الدرجات" ====================

public function grades(Request $request)
{
    $breakdown = $this->gradeService->currentSemesterBreakdown($request->user()->student);

    return response()->json(['success' => true, 'data' => $breakdown]);
}

// أضف هذا التابع بـ StudentController

// زملاء الطالب بنفس الشعبة (تُستدعى عند فتح/الضغط على "انضمام")
public function classmates(Request $request)
{
    $student = $request->user()->student;

    if (!$student->section_id) {
        return response()->json(['success' => true, 'data' => []]);
    }

    $classmates = Student::where('section_id', $student->section_id)
        ->where('id', '!=', $student->id)
        ->with('user:id,user_name,profile_photo_path')
        ->get()
        ->map(fn ($classmate) => [
            'student_id' => $classmate->id,
            'user_name' => $classmate->user->user_name,
            'profile_photo_url' => $classmate->user->profile_photo_path
                ? asset('storage/' . $classmate->user->profile_photo_path)
                : null,
        ]);

    return response()->json(['success' => true, 'data' => $classmates]);
}

// معلومات "مجموعة الشعبة" (الاسم + عدد الأعضاء، للبطاقة قبل الضغط على انضمام)
public function classGroup(Request $request)
{
    $student = $request->user()->student;

    if (!$student->section_id) {
        return response()->json(['success' => false, 'message' => 'الطالب غير مسجّل بشعبة'], 400);
    }

    $section = $student->section()->with('schoolClass:id,name')->first();
    $membersCount = Student::where('section_id', $student->section_id)->count();

    return response()->json([
        'success' => true,
        'data' => [
            'group_name' => "مجموعة {$section->schoolClass->name} - {$section->name}",
            'members_count' => $membersCount,
        ],
    ]);
}
public function studentProfile(Request $request)
{
    $user = $request->user();
    $student = $user->student;
    $stage = $student->schoolClass?->stage;

    return response()->json([
        'success' => true,
        'data' => [
            'user_name' => $user->user_name,
            'profile_photo_url' => $user->profile_photo_path ? asset('storage/' . $user->profile_photo_path) : null,
            'student_number' => $student->student_number,
            'class' => $student->schoolClass?->name,
            'stage' => $stage?->name,
            'track' => $stage?->track_label,
            'section' => $student->section?->name,
            'enrollment_date' => $student->enrollment_date,
            'status' => $user->status,
        ],
    ]);
}

// الحضور والغياب (نسبة + تأخير + أيام غياب)
public function attendanceSummary(Request $request)
{
    $student = $request->user()->student;

    $total = Attendance::where('student_id', $student->id)->count();
    $present = Attendance::where('student_id', $student->id)->where('status', 'present')->count();
    $late = Attendance::where('student_id', $student->id)->where('status', 'late')->count();
    $absent = Attendance::where('student_id', $student->id)->where('status', 'absent')->count();

    return response()->json([
        'success' => true,
        'data' => [
            'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 1) : null,
            'late_count' => $late,
            'absent_days' => $absent,
        ],
    ]);
}

// معلومات ولي الأمر (أول ولي أمر مرتبط)
public function guardianInfo(Request $request)
{
    $student = $request->user()->student;
    $guardian = $student->guardians()->with('user:id,user_name,email,phone')->first();

    if (!$guardian) {
        return response()->json(['success' => true, 'data' => null]);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'name' => $guardian->user->user_name,
            'relationship' => $guardian->relationship,
            'phone' => $guardian->user->phone,
            'email' => $guardian->user->email,
        ],
    ]);
}

// البيانات الشخصية (تعديل ذاتي — الجنسية/الهوية/العنوان/الملاحظة الطبية)
public function updatePersonalInfo(Request $request)
{
    $validator = Validator::make($request->all(), [
        'nationality' => 'nullable|string|max:100',
        'national_id' => 'nullable|string|max:50|unique:students,national_id,' . $request->user()->student->id,
        'address' => 'nullable|string|max:255',
        'medical_notes' => 'nullable|string|max:1000',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $student = $request->user()->student;
    $student->update($request->only(['nationality', 'national_id', 'address', 'medical_notes']));

    return response()->json(['success' => true, 'message' => 'تم تحديث البيانات الشخصية', 'data' => $student]);
}

public function recentActivity(Request $request)
{
    $activities = $this->assignmentService->recentActivityForStudent($request->user()->student);

    return response()->json(['success' => true, 'data' => $activities]);
}
public function assignmentProgressDetails(Request $request)
{
    $assignments = $this->assignmentService->todayDetailedForStudent($request->user()->student);

    return response()->json(['success' => true, 'data' => $assignments]);
}

public function courses(Request $request)
{
    $courses = $this->gradeService->coursesForStudent($request->user()->student);

    return response()->json(['success' => true, 'data' => $courses]);
}


}
