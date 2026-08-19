<?php
namespace App\Services;

use App\Models\Assignment;
use App\Notifications\AssignmentPublished;
use App\Services\Notifications\NotificationDispatcher;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\StudentAssignmentStatus;
use App\Models\Teacher;
use App\Models\TeacherAssignment;

class AssignmentService
{
    private const URGENT_DAYS_THRESHOLD = 2;

    // ==================== توابع المعلم (بدون تغيير) ====================

    public function subjectsForTeacher(Teacher $teacher)
    {
        return $teacher->assignments()
            ->with('subject:id,name')
            ->get()
            ->pluck('subject')
            ->unique('id')
            ->values();
    }

    public function sectionsForSubject(Teacher $teacher, int $subjectId)
    {
        return $teacher->assignments()
            ->where('subject_id', $subjectId)
            ->with('section.schoolClass:id,name')
            ->get()
            ->pluck('section')
            ->unique('id')
            ->values();
    }

    public function findTeacherAssignment(Teacher $teacher, int $subjectId, int $sectionId): ?TeacherAssignment
    {
        return TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('subject_id', $subjectId)
            ->where('section_id', $sectionId)
            ->first();
    }

    public function create(TeacherAssignment $teacherAssignment, array $data): Assignment
    {
        $assignment = Assignment::create([
            'teacher_assignment_id' => $teacherAssignment->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'max_grade' => $data['max_grade'] ?? 100,
            'attachment_path' => $data['attachment_path'] ?? null,
            'attachment_link' => $data['attachment_link'] ?? null,
        ]);

        $this->notifyStudents($assignment, $teacherAssignment);

        return $assignment;
    }

    /**
     * إشعار طلاب الشعبة بالواجب الجديد.
     *
     * الإرسال بينحط بالطابور (الإشعار ShouldQueue)، فما بيأخّر الرد.
     * ولو فشل الإشعار لأي سبب، الواجب بيضل محفوظ — إنشاء الواجب أهم من
     * إشعاره، وما بصير خطأ ببث لحظي يوقّع العملية كلها.
     */
    protected function notifyStudents(Assignment $assignment, TeacherAssignment $teacherAssignment): void
    {
        try {
            $dispatcher = app(NotificationDispatcher::class);

            $dispatcher->send(
                $dispatcher->to()->studentsOfSection($teacherAssignment->section_id),
                new AssignmentPublished($assignment->load('teacherAssignment.subject', 'teacherAssignment.teacher.user'))
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function list(Teacher $teacher, array $filters)
    {
        $query = Assignment::whereHas('teacherAssignment', function ($q) use ($teacher) {
            $q->where('teacher_id', $teacher->id);
        });

        if (!empty($filters['section_id'])) {
            $query->whereHas('teacherAssignment', fn ($q) => $q->where('section_id', $filters['section_id']));
        }

        if (!empty($filters['subject_id'])) {
            $query->whereHas('teacherAssignment', fn ($q) => $q->where('subject_id', $filters['subject_id']));
        }

        return $query->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }

    // ==================== توابع الطالب ====================

    // واجبات مستحقة اليوم (للوحة التحكم الرئيسية)
    public function todayForStudent(Student $student)
    {
        if (!$student->section_id) {
            return collect();
        }

        return Assignment::whereHas('teacherAssignment', fn ($q) => $q->where('section_id', $student->section_id))
            ->whereDate('due_date', now()->toDateString())
            ->with('teacherAssignment.subject:id,name')
            ->get();
    }

    // كل واجبات الطالب مع حالته الشخصية + شارة "هام جداً" الديناميكية
   public function listForStudentWithStatus(Student $student, array $filters = [])
{
    if (!$student->section_id) {
        return collect();
    }

    $query = Assignment::whereHas('teacherAssignment', fn ($q) => $q->where('section_id', $student->section_id));

    if (!empty($filters['status'])) {
        if ($filters['status'] === 'completed') {
            $query->whereHas('studentStatuses', function ($q) use ($student) {
                $q->where('student_id', $student->id)->where('status', 'completed');
            });
        } else {
            $query->whereDoesntHave('studentStatuses', function ($q) use ($student) {
                $q->where('student_id', $student->id)->where('status', 'completed');
            });
        }
    }

    $assignments = $query->with('teacherAssignment.subject:id,name')
        ->orderBy('due_date', 'asc')
        ->get();

    // ✅ التأكد من وجود مهام
    if ($assignments->isEmpty()) {
        return collect(); // أو return $assignments;
    }

    // ✅ الآن بأمان نجيب الحالات
    $statuses = StudentAssignmentStatus::where('student_id', $student->id)
        ->whereIn('assignment_id', $assignments->pluck('id'))
        ->pluck('status', 'assignment_id');

    $today = now()->startOfDay();

    foreach ($assignments as $assignment) {
        // ✅ استخدام ?? بدلاً من get مع default
        $status = $statuses->get($assignment->id) ?? 'in_progress';
        $assignment->status = $status;

        $isUrgent = false;
        if ($status !== 'completed' && $assignment->due_date) {
            $daysLeft = $today->diffInDays($assignment->due_date, false);
            $isUrgent = $daysLeft >= 0 && $daysLeft <= self::URGENT_DAYS_THRESHOLD;
        }
        $assignment->is_urgent = $isUrgent;
    }

    return $assignments;
}

    // نسبة إنجاز اليوم: من كل المهام اللي موعدها لسا ما اجا، كم أنجزه الطالب اليوم بالذات
public function todayProgressForStudent(Student $student): array
{
    if (!$student->section_id) {
        return ['completed' => 0, 'total' => 0, 'percentage' => 0];
    }

    $today = now()->toDateString();

    // 1. المهام المستقبلية (اليوم أو بعد)
    $futureAssignments = Assignment::whereHas('teacherAssignment', fn ($q) => $q->where('section_id', $student->section_id))
        ->whereDate('due_date', '>=', $today)
        ->pluck('id'); // نجيب الـ IDs

    $totalNotYetDue = $futureAssignments->count();

    if ($totalNotYetDue === 0) {
        return ['completed' => 0, 'total' => 0, 'percentage' => 0];
    }

    // 2. المهام المنجزة اليوم من بين هذه المهام فقط
    $completedToday = StudentAssignmentStatus::where('student_id', $student->id)
        ->where('status', 'completed')
        ->whereDate('updated_at', $today)
        ->whereIn('assignment_id', $futureAssignments) // نفس المهام بالضبط
        ->distinct('assignment_id') // لو في تكرار
        ->count('assignment_id');

    // 3. تأكد أن البسط لا يزيد عن المقام
    $completedToday = min($completedToday, $totalNotYetDue);

    return [
        'completed' => $completedToday,
        'total' => $totalNotYetDue,
        'percentage' => (int) round(($completedToday / $totalNotYetDue) * 100),
    ];
}
    // زر "تسليم" = تحديد المهمة كمكتملة (شخصي بحت، بدون إشعار للمعلم)
    public function markCompleted(Student $student, int $assignmentId): StudentAssignmentStatus
    {
        return StudentAssignmentStatus::updateOrCreate(
            ['assignment_id' => $assignmentId, 'student_id' => $student->id],
            ['status' => 'completed']
        );
    }

public function recentActivityForStudent(Student $student, int $limit = 10): array
{
    $activities = collect();

    // 1) واجبات مسلّمة مؤخراً
    $submittedAssignments = StudentAssignmentStatus::where('student_id', $student->id)
        ->where('status', 'completed')
        ->with('assignment.teacherAssignment.subject')
        ->latest('updated_at')
        ->limit($limit)
        ->get();

    foreach ($submittedAssignments as $entry) {
        $activities->push([
            'type' => 'assignment_submitted',
            'title' => 'تم تسليم واجب',
            'description' => $entry->assignment->title . ' - ' . $entry->assignment->teacherAssignment->subject->name,
            'date' => $entry->updated_at,
        ]);
    }

    // 2) سجلات حضور مؤخراً
    $attendanceRecords = Attendance::where('student_id', $student->id)
        ->latest('date')
        ->limit($limit)
        ->get();

    foreach ($attendanceRecords as $record) {
        $statusLabel = match ($record->status) {
            'present' => 'تسجيل حضور',
            'absent' => 'تسجيل غياب',
            'late' => 'تسجيل تأخير',
            'excused' => 'غياب بعذر',
        };

        $activities->push([
            'type' => 'attendance',
            'title' => $statusLabel,
            'description' => 'بتاريخ ' . \Carbon\Carbon::parse($record->date)->format('Y-m-d'),
            'date' => $record->date,
        ]);
    }

    // ترتيب الكل حسب التاريخ (الأحدث أولاً)، وقص للعدد المطلوب
    return $activities->sortByDesc('date')->take($limit)->values()->toArray();
}


// تفاصيل مهام اليوم مع حالة كل واحدة (لزر "تفاصيل التقدم")
// تفاصيل المهام اللي أنجزها الطالب اليوم بالذات (من ضمن المهام غير المستحقة بعد)
public function todayDetailedForStudent(Student $student)
{
    if (!$student->section_id) {
        return collect();
    }

    $today = now()->toDateString();

    return Assignment::whereHas('teacherAssignment', fn ($q) => $q->where('section_id', $student->section_id))
        ->whereDate('due_date', '>=', $today)
        ->whereHas('studentStatuses', function ($q) use ($student, $today) {
            $q->where('student_id', $student->id)
              ->where('status', 'completed')
              ->whereDate('updated_at', $today);
        })
        ->with('teacherAssignment.subject:id,name')
        ->get();
}
}
