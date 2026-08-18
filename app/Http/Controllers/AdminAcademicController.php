<?php
namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\TeacherAssignment;
use App\Services\GradeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminAcademicController extends Controller
{
    public function dailyAttendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'section_id' => 'required|integer|exists:sections,id',
            'date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $section = Section::with('schoolClass')->find($request->section_id);

        $records = Attendance::with('student.user:id,user_name')
            ->where('section_id', $request->section_id)
            ->whereDate('date', $request->date)
            ->get()
            ->map(fn ($a) => [
                'student_id' => $a->student_id,
                'student_name' => $a->student->user->user_name,
                'student_number' => $a->student->student_number,
                'status' => $a->status,
                'excuse' => $a->excuse,
                'left_at' => $a->left_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'section' => $section->name,
                'class' => $section->schoolClass->name,
                'date' => $request->date,
                'records' => $records,
            ],
        ]);
    }

    public function studentAttendanceHistory(Request $request, Student $student)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = $student->attendances()->orderByDesc('date');
        if ($request->from) $query->whereDate('date', '>=', $request->from);
        if ($request->to) $query->whereDate('date', '<=', $request->to);

        $records = $query->get(['date', 'status', 'excuse']);

        return response()->json([
            'success' => true,
            'data' => [
                'student_id' => $student->id,
                'student_name' => $student->user->user_name,
                'summary' => [
                    'present' => $records->where('status', 'present')->count(),
                    'absent' => $records->where('status', 'absent')->count(),
                    'late' => $records->where('status', 'late')->count(),
                    'excused' => $records->where('status', 'excused')->count(),
                    'total_days' => $records->count(),
                ],
                'records' => $records,
            ],
        ]);
    }

    public function attendanceRateReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'class_id' => 'nullable|integer|exists:classes,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = Attendance::query()
            ->whereBetween('date', [$request->from, $request->to])
            ->join('sections', 'attendance.section_id', '=', 'sections.id')
            ->join('classes', 'sections.class_id', '=', 'classes.id');

        if ($request->class_id) {
            $query->where('classes.id', $request->class_id);
        }

        $stats = $query
            ->selectRaw('sections.id as section_id, sections.name as section_name, classes.name as class_name,
                COUNT(*) as total,
                SUM(CASE WHEN attendance.status = "present" THEN 1 ELSE 0 END) as present_count')
            ->groupBy('sections.id', 'sections.name', 'classes.name')
            ->get()
            ->map(fn ($row) => [
                'section_id' => $row->section_id,
                'section_name' => $row->section_name,
                'class_name' => $row->class_name,
                'attendance_rate' => $row->total > 0 ? round(($row->present_count / $row->total) * 100, 1) : null,
            ]);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function mostAbsentStudents(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $students = Attendance::query()
            ->whereBetween('date', [$request->from, $request->to])
            ->where('status', 'absent')
            ->selectRaw('student_id, COUNT(*) as absent_count')
            ->groupBy('student_id')
            ->orderByDesc('absent_count')
            ->limit($request->limit ?? 10)
            ->with('student.user:id,user_name')
            ->get()
            ->map(fn ($row) => [
                'student_id' => $row->student_id,
                'student_name' => $row->student->user->user_name,
                'student_number' => $row->student->student_number,
                'absent_count' => $row->absent_count,
            ]);

        return response()->json(['success' => true, 'data' => $students]);
    }

    public function studentsWithoutSection()
    {
        $students = Student::whereNull('section_id')
            ->with(['user:id,user_name', 'schoolClass'])
            ->get()
            ->map(fn ($s) => [
                'student_id' => $s->id,
                'student_name' => $s->user->user_name,
                'student_number' => $s->student_number,
                'class' => $s->schoolClass?->name,
            ]);

        return response()->json(['success' => true, 'data' => $students]);
    }

    public function studentsDistribution()
    {
        $distribution = SchoolClass::withCount('students')
            ->orderBy('grade_order')
            ->get()
            ->map(fn ($class) => [
                'class' => $class->name,
                'total_students' => $class->students_count,
            ]);

        return response()->json(['success' => true, 'data' => $distribution]);
    }

    public function pendingGradeSubmissions(Request $request)
    {
        $submissions = GradeSubmission::where('status', 'submitted')
            ->with(
                'teacherAssignment.subject:id,name',
                'teacherAssignment.section.schoolClass:id,name',
                'teacherAssignment.teacher.user:id,user_name'
            )
            ->get();

        return response()->json(['success' => true, 'data' => $submissions]);
    }

    public function approveGradeSubmission(Request $request, int $submissionId, GradeService $gradeService)
    {
        $submission = GradeSubmission::find($submissionId);

        if (!$submission) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
        }

        if ($submission->status !== 'submitted') {
            return response()->json(['success' => false, 'message' => 'لا يمكن اعتماد طلب غير مرسل'], 400);
        }

        $submission->update(['status' => 'approved', 'approved_by' => $request->user()->id]);

        $grades = $gradeService->computeFinalGrades($submission->teacherAssignment, $submission->semester);

        return response()->json(['success' => true, 'message' => 'تم اعتماد علامات الشعبة', 'data' => $grades]);
    }

    public function rejectGradeSubmission(int $submissionId)
    {
        $submission = GradeSubmission::find($submissionId);

        if (!$submission) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
        }

        $submission->update(['status' => 'rejected']);

        return response()->json(['success' => true, 'message' => 'تم رفض الطلب، يمكن للمعلم التعديل الآن']);
    }

    public function gradeStatistics(Request $request)
{
    $validator = Validator::make($request->all(), [
        'subject_id' => 'nullable|exists:subjects,id',
        'section_id' => 'nullable|exists:sections,id',
        'semester' => 'required|integer|in:1,2',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $assignmentQuery = TeacherAssignment::query();

    if ($request->subject_id) {
        $assignmentQuery->where('subject_id', $request->subject_id);
    }

    if ($request->section_id) {
        $assignmentQuery->where('section_id', $request->section_id);
    }

    $assignmentIds = $assignmentQuery->pluck('id');
    $totalAssignments = $assignmentIds->count();

    $submissions = GradeSubmission::whereIn('teacher_assignment_id', $assignmentIds)
        ->where('semester', $request->semester)
        ->get();

    $submitted = $submissions->where('status', 'submitted')->count();
    $approved = $submissions->where('status', 'approved')->count();
    $rejected = $submissions->where('status', 'rejected')->count();
    $notSubmitted = $totalAssignments - $submissions->count();

    return response()->json([
        'success' => true,
        'data' => [
            'total_assignments' => $totalAssignments,
            'not_submitted' => $notSubmitted,
            'submitted' => $submitted,
            'approved' => $approved,
            'rejected' => $rejected,
            'not_submitted_percentage' => $totalAssignments > 0 ? round(($notSubmitted / $totalAssignments) * 100, 1) : 0,
            'submitted_percentage' => $totalAssignments > 0 ? round(($submitted / $totalAssignments) * 100, 1) : 0,
            'approved_percentage' => $totalAssignments > 0 ? round(($approved / $totalAssignments) * 100, 1) : 0,
            'rejected_percentage' => $totalAssignments > 0 ? round(($rejected / $totalAssignments) * 100, 1) : 0,
        ]
    ]);
}
    public function studentReportCard(Request $request, Student $student, GradeService $gradeService)
{
    $validator = Validator::make($request->all(), [
        'semester' => 'required|integer|in:1,2',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $semester = $request->semester;

    // مادة وحدة = صف واحد، حتى لو بياخدها أكتر من معلم لنفس الشعبة
    $teacherAssignments = TeacherAssignment::where('section_id', $student->section_id)
        ->with('subject')
        ->get()
        ->unique('subject_id');

    $report = [];

    foreach ($teacherAssignments as $assignment) {
        $submission = GradeSubmission::where('section_id', $assignment->section_id)
            ->where('subject_id', $assignment->subject_id)
            ->where('semester', $semester)
            ->where('status', 'approved')
            ->first();

        if (!$submission) {
            continue; // العلامة النهائية لهذه المادة لسا مو معتمدة، لا تظهر بكشف الدرجات
        }

        $computed = $gradeService->computeFinalGrades($assignment, $semester);
        $studentGrade = collect($computed)->firstWhere('student_id', $student->id);

        if (!$studentGrade) {
            continue;
        }

        $report[] = [
            'subject' => $assignment->subject->name,
            'total_value' => $studentGrade['total_value'],
            'passing_grade' => $assignment->subject->passing_grade,
            'passed' => $studentGrade['total_value'] >= $assignment->subject->passing_grade,
        ];
    }

    return response()->json(['success' => true, 'data' => $report]);
}
}
