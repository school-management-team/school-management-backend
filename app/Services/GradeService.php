<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;

class GradeService
{
    // أسماء مكوّنات العلامة وأوزانها من config/school.php (مطابقة لـ enum grades.type)
    public function components(): array
    {
        return config('school.grade_components');
    }

    // أسماء المكوّنات بس: participation, quiz, exam
    public function componentTypes(): array
    {
        return array_keys($this->components());
    }

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

    /*
     | دفتر العلامات مفتاحه (شعبة + مادة) — مش التكليف.
     | التكليف بيحدد الصلاحية بس (هل بتدرّس هالمادة لهالشعبة؟)، فلو أكتر من
     | معلم بياخد نفس المادة لنفس الشعبة بيشتغلوا كلهم على نفس الدفتر.
     */

    // هل هذي المادة مقفلة (مرفوعة/معتمدة) لهذه الشعبة بهذا الفصل؟
    public function isLocked(TeacherAssignment $teacherAssignment, int $semester): bool
    {
        return GradeSubmission::where('section_id', $teacherAssignment->section_id)
            ->where('subject_id', $teacherAssignment->subject_id)
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

        $entered = Grade::where('subject_id', $teacherAssignment->subject_id)
            ->where('semester', $semester)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        foreach ($studentIds as $studentId) {
            $types = $entered->get($studentId, collect())->pluck('type');

            foreach ($this->componentTypes() as $type) {
                if (!$types->contains($type)) {
                    return false;
                }
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
                'student_id' => $data['student_id'],
                'subject_id' => $teacherAssignment->subject_id,
                'type' => $data['type'],
                'semester' => $data['semester'],
            ],
            [
                'section_id' => $teacherAssignment->section_id,
                'teacher_assignment_id' => $teacherAssignment->id,
                'value' => $data['value'],
            ]
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

        $existingGrades = Grade::where('subject_id', $teacherAssignment->subject_id)
            ->where('type', $type)
            ->where('semester', $semester)
            ->whereIn('student_id', $students->pluck('id'))
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
                    'student_id' => $entry['student_id'],
                    'subject_id' => $teacherAssignment->subject_id,
                    'type' => $type,
                    'semester' => $semester,
                ],
                [
                    'section_id' => $teacherAssignment->section_id,
                    'teacher_assignment_id' => $teacherAssignment->id,
                    'value' => $entry['value'],
                ]
            );
        }

        $this->autoSubmitIfComplete($teacherAssignment, $semester);
    }

    // حساب المحصّلة لكل طلاب الشعبة (يُستخدم فقط بعد اكتمال البيانات)
    public function computeFinalGrades(TeacherAssignment $teacherAssignment, int $semester): array
    {
        $students = $teacherAssignment->section->students;

        $grades = Grade::where('subject_id', $teacherAssignment->subject_id)
            ->where('semester', $semester)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->groupBy('student_id');

        $results = [];

        foreach ($students as $student) {
            $studentGrades = $grades->get($student->id, collect());

            $row = [
                'student_id' => $student->id,
                'student_name' => $student->user->user_name,
            ];

            $total = 0;

            foreach ($this->components() as $type => $component) {
                $value = $studentGrades->firstWhere('type', $type)?->value;
                $row["{$type}_value"] = $value;
                $total += $value * $component['weight'] / 100;
            }

            $row['total_value'] = round($total, 2);
            $results[] = $row;
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
            [
                'section_id' => $teacherAssignment->section_id,
                'subject_id' => $teacherAssignment->subject_id,
                'semester' => $semester,
            ],
            [
                'teacher_assignment_id' => $teacherAssignment->id,
                'status' => 'submitted',
                'approved_by' => null,
            ]
        );

        return true;
    }
}
