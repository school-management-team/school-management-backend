<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;

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
            ->whereIn('status', ['submitted', 'approved'])
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

            if (!$typesEntered->contains('participation') || !$typesEntered->contains('study') || !$typesEntered->contains('exam')) {
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
            $study = $studentGrades->firstWhere('type', 'study')?->value;
            $exam = $studentGrades->firstWhere('type', 'exam')?->value;

            $total = ($participation * self::PARTICIPATION_WEIGHT / 100)
                + ($study * self::STUDY_WEIGHT / 100)
                + ($exam * self::EXAM_WEIGHT / 100);

            $results[] = [
                'student_id' => $student->id,
                'student_name' => $student->user->user_name,
                'participation_value' => $participation,
                'study_value' => $study,
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
}
