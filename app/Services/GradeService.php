<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TeacherTask;

class GradeService
{
    private const PARTICIPATION_WEIGHT = 20;
    private const STUDY_WEIGHT = 30;
    private const EXAM_WEIGHT = 50;

    // كل الشعب اللي يدرّسها المعلم (لبطاقات الواجهة)
    public function sectionsForTeacher(Teacher $teacher)
    {
        $assignments = TeacherAssignment::where('teacher_id', $teacher->id)
            ->with('subject:id,name', 'section.schoolClass:id,name')
            ->get();

        foreach ($assignments as $assignment) {
            $assignment->students_count = $assignment->section->students()->count();
        }

        return $assignments;
    }

    // التأكد من أن هذه الشعبة فعلاً تخص هذا المعلم
    public function findTeacherAssignmentById(Teacher $teacher, int $teacherAssignmentId): ?TeacherAssignment
    {
        return TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('id', $teacherAssignmentId)
            ->first();
    }

    // التأكد من أن الطالب فعلاً منتمٍ لهذه الشعبة
    public function studentBelongsToSection(int $studentId, int $sectionId): bool
    {
        return Student::where('id', $studentId)
            ->where('section_id', $sectionId)
            ->exists();
    }

    // هل هذي الشعبة مقفلة (مرفوعة/معتمدة) لهذا الفصل؟
public function isLocked(TeacherAssignment $teacherAssignment, int $semester): bool
{
    return GradeSubmission::where('teacher_assignment_id', $teacherAssignment->id)
        ->where('semester', $semester)
        ->where('status', 'approved')   // ← بدل whereIn(['submitted', 'approved'])
        ->exists();
}

    // التحقق: هل كل طلاب الشعبة عندهم الأنواع الثلاثة كاملة لهذا الفصل؟
    public function isSectionComplete(TeacherAssignment $teacherAssignment, int $semester): bool
    {
        $studentIds = $teacherAssignment->section->students()->pluck('id');

        if ($studentIds->isEmpty()) {
            return false;
        }

        foreach ($studentIds as $studentId) {
            $typesEntered = Grade::where('teacher_assignment_id', $teacherAssignment->id)
                ->where('student_id', $studentId)
                ->where('semester', $semester)
                ->pluck('type');

            if (!$typesEntered->contains('participation') || !$typesEntered->contains('quiz') || !$typesEntered->contains('exam')) {
                return false;
            }
        }

        return true;
    }

    // إضافة علامة فردية (إجراء سريع)
    public function store(TeacherAssignment $teacherAssignment, array $data): Grade
    {
        if ($this->isLocked($teacherAssignment, $data['semester'])) {
            throw new \Exception('لا يمكن تعديل العلامات بعد اكتمالها ورفعها للاعتماد');
        }

        $grade = Grade::updateOrCreate(
            [
                'teacher_assignment_id' => $teacherAssignment->id,
                'student_id' => $data['student_id'],
                'type' => $data['type'],
                'semester' => $data['semester'],
            ],
            ['value' => $data['value']]
        );

        $this->autoSubmitIfComplete($teacherAssignment, $data['semester']);

        return $grade;
    }

    // قائمة طلاب الشعبة، مع علامتهم الحالية لهذا النوع ولهذا الفصل
    public function studentsForGrading(TeacherAssignment $teacherAssignment, string $type, int $semester)
    {
        $students = $teacherAssignment->section->students()
            ->with('user:id,user_name')
            ->get();

        $existingGrades = Grade::where('teacher_assignment_id', $teacherAssignment->id)
            ->where('type', $type)
            ->where('semester', $semester)
            ->pluck('value', 'student_id');

        foreach ($students as $student) {
            $student->existing_grade = $existingGrades->get($student->id);
        }

        return $students;
    }

    // حفظ علامات كل طلاب الشعبة دفعة وحدة (رصد جماعي)
    public function saveBulkGrades(TeacherAssignment $teacherAssignment, string $type, int $semester, array $grades): void
    {
        if ($this->isLocked($teacherAssignment, $semester)) {
            throw new \Exception('لا يمكن تعديل العلامات بعد اكتمالها ورفعها للاعتماد');
        }

        foreach ($grades as $entry) {
            Grade::updateOrCreate(
                [
                    'teacher_assignment_id' => $teacherAssignment->id,
                    'student_id' => $entry['student_id'],
                    'type' => $type,
                    'semester' => $semester,
                ],
                ['value' => $entry['value']]
            );
        }

        $this->autoSubmitIfComplete($teacherAssignment, $semester);
    }

    // حساب المحصّلة لكل طلاب الشعبة (يُستخدم فقط بعد اكتمال البيانات)
    public function computeFinalGrades(TeacherAssignment $teacherAssignment, int $semester): array
    {
        $students = $teacherAssignment->section->students;

        $grades = Grade::where('teacher_assignment_id', $teacherAssignment->id)
            ->where('semester', $semester)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->groupBy('student_id');

        $results = [];

        foreach ($students as $student) {
            $studentGrades = $grades->get($student->id, collect());

            $participation = $studentGrades->firstWhere('type', 'participation')?->value;
            $quiz = $studentGrades->firstWhere('type', 'quiz')?->value;
            $exam = $studentGrades->firstWhere('type', 'exam')?->value;

            $total = ($participation * self::PARTICIPATION_WEIGHT / 100)
                + ($quiz * self::STUDY_WEIGHT / 100)
                + ($exam * self::EXAM_WEIGHT / 100);

            $results[] = [
                'student_id' => $student->id,
                'student_name' => $student->user->user_name,
                'participation_value' => $participation,
                'quiz_value' => $quiz,
                'exam_value' => $exam,
                'total_value' => round($total, 2),
            ];
        }

        return $results;
    }

    // يُستدعى تلقائياً بعد كل حفظ علامات — يفحص الاكتمال ويرفع تلقائياً لو تم
    public function autoSubmitIfComplete(TeacherAssignment $teacherAssignment, int $semester): bool
    {
        if ($this->isLocked($teacherAssignment, $semester)) {
            return false;
        }

        if (!$this->isSectionComplete($teacherAssignment, $semester)) {
            return false;
        }

        GradeSubmission::updateOrCreate(
            ['teacher_assignment_id' => $teacherAssignment->id, 'semester' => $semester],
            ['status' => 'submitted', 'approved_by' => null]
        );

        return true;
    }


// تفصيل علامات الطالب بفصل معيّن (أو كل الفصول لو null)
public function subjectBreakdownForStudent(Student $student, ?int $semester = null): array
{
    if (!$student->section_id) {
        return [];
    }

    $query = GradeSubmission::where('status', 'approved')
        ->whereHas('teacherAssignment', fn ($q) => $q->where('section_id', $student->section_id));

    if ($semester !== null) {
        $query->where('semester', $semester);
    }

    $submissions = $query->with('teacherAssignment.subject')->get();

    $result = [];

    foreach ($submissions as $submission) {
        $computed = $this->computeFinalGrades($submission->teacherAssignment, $submission->semester);
        $studentGrade = collect($computed)->firstWhere('student_id', $student->id);

        if (!$studentGrade || $studentGrade['total_value'] === null) {
            continue;
        }

        $subject = $submission->teacherAssignment->subject;

        $result[] = [
            'subject' => $subject->name,
            'total_value' => $studentGrade['total_value'],
            'passing_grade' => $subject->passing_grade,
            'passed' => $studentGrade['total_value'] >= $subject->passing_grade,
        ];
    }

    return $result;
}

// آخر فصل صدرت فيه علامات معتمدة (الثاني أولاً، ثم الأول، ثم رسالة عدم الصدور)
public function currentSemesterBreakdown(Student $student): array
{
    foreach ([2, 1] as $semester) {
        $breakdown = $this->subjectBreakdownForStudent($student, $semester);

        if (!empty($breakdown)) {
            $totals = array_column($breakdown, 'total_value');

            return [
                'semester' => $semester,
                'semester_label' => $semester === 1 ? 'الفصل الدراسي الأول' : 'الفصل الدراسي الثاني',
                'average_grade_100' => round(array_sum($totals) / count($totals), 2),
                'subjects' => $breakdown,
                'message' => null,
            ];
        }
    }

    return [
        'semester' => null,
        'semester_label' => null,
        'average_grade_100' => null,
        'subjects' => [],
        'message' => 'لم تصدر الدرجات بعد',
    ];
}

// متوسط الطالب (تُستخدم بلوحة التحكم الرئيسية)
public function averageForStudent(Student $student): ?float
{
    return $this->currentSemesterBreakdown($student)['average_grade_100'];
}


public function recentActivityForTeacher(Teacher $teacher, int $limit = 10): array
{
    $activities = collect();

    // 1) مهام أنشأها المعلم مؤخراً
    $tasks = TeacherTask::whereHas('teacherAssignment', fn ($q) => $q->where('teacher_id', $teacher->id))
        ->with('teacherAssignment.section.schoolClass:id,name')
        ->latest('created_at')
        ->limit($limit)
        ->get();

    foreach ($tasks as $task) {
        $activities->push([
            'type' => 'task_created',
            'title' => 'مهمة جديدة: ' . $task->title,
            'description' => $task->teacherAssignment->section->schoolClass->name . ' - ' . $task->teacherAssignment->section->name,
            'date' => $task->created_at,
        ]);
    }

    // 2) علامات شعب رُفعت أو اعتُمدت مؤخراً
    $submissions = GradeSubmission::whereHas('teacherAssignment', fn ($q) => $q->where('teacher_id', $teacher->id))
        ->with('teacherAssignment.section.schoolClass:id,name')
        ->latest('updated_at')
        ->limit($limit)
        ->get();

    foreach ($submissions as $submission) {
        $label = $submission->status === 'approved' ? 'تم اعتماد درجات' : 'تم رصد درجات';
        $semesterLabel = $submission->semester === 1 ? 'الفصل الأول' : 'الفصل الثاني';

        $activities->push([
            'type' => 'grade_submission',
            'title' => $label,
            'description' => $submission->teacherAssignment->section->schoolClass->name . ' - ' . $submission->teacherAssignment->section->name,
            'date' => $submission->updated_at,
        ]);
    }

    return $activities->sortByDesc('date')->take($limit)->values()->toArray();
}
}
