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
    // أسماء مكوّنات العلامة وأوزانها من config/school.php (مطابقة لـ enum grades.type)
    public function components(): array
    {
        return config('school.grade_components');
    }

    public function componentTypes(): array
    {
        return array_keys($this->components());
    }

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

    public function findTeacherAssignmentById(Teacher $teacher, int $teacherAssignmentId): ?TeacherAssignment
    {
        return TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('id', $teacherAssignmentId)
            ->first();
    }

    public function studentBelongsToSection(int $studentId, int $sectionId): bool
    {
        return Student::where('id', $studentId)
            ->where('section_id', $sectionId)
            ->exists();
    }

    public function studentsOutsideSection(TeacherAssignment $teacherAssignment, array $studentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $studentIds)));

        if ($ids === []) {
            return [];
        }

        $inside = Student::whereIn('id', $ids)
            ->where('section_id', $teacherAssignment->section_id)
            ->pluck('id')
            ->all();

        return array_values(array_diff($ids, $inside));
    }

    /*
     | دفتر العلامات مفتاحه (شعبة + مادة) — مش التكليف.
     | التكليف بيحدد الصلاحية بس (هل بتدرّس هالمادة لهالشعبة؟)، فلو أكتر من
     | معلم بياخد نفس المادة لنفس الشعبة بيشتغلوا كلهم على نفس الدفتر.
     */

    // هل هذي المادة مقفلة (معتمدة) لهذه الشعبة بهذا الفصل؟
    public function isLocked(TeacherAssignment $teacherAssignment, int $semester): bool
    {
        return GradeSubmission::where('section_id', $teacherAssignment->section_id)
            ->where('subject_id', $teacherAssignment->subject_id)
            ->where('semester', $semester)
            ->where('status', 'approved')   // بعد الاعتماد بس، مو بعد الرفع (قرارنا السابق)
            ->exists();
    }

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

    public function store(TeacherAssignment $teacherAssignment, array $data): Grade
    {
        if ($this->isLocked($teacherAssignment, $data['semester'])) {
            throw new \Exception('لا يمكن تعديل العلامات بعد اكتمالها ورفعها للاعتماد');
        }

        if ($this->studentsOutsideSection($teacherAssignment, [$data['student_id']]) !== []) {
            throw new \Exception('هذا الطالب غير منتمٍ لهذه الشعبة');
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

    public function saveBulkGrades(TeacherAssignment $teacherAssignment, string $type, int $semester, array $grades): void
    {
        if ($this->isLocked($teacherAssignment, $semester)) {
            throw new \Exception('لا يمكن تعديل العلامات بعد اكتمالها ورفعها للاعتماد');
        }

        $outsiders = $this->studentsOutsideSection($teacherAssignment, array_column($grades, 'student_id'));

        if ($outsiders !== []) {
            throw new \Exception('بعض الطلاب غير منتمين لهذه الشعبة: '.implode('، ', $outsiders));
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
            $missing = 0;

            foreach ($this->components() as $type => $component) {
                $value = $studentGrades->firstWhere('type', $type)?->value;
                $row["{$type}_value"] = $value;

                if ($value === null) {
                    $missing++;
                    continue;
                }

                $total += $value * $component['weight'] / 100;
            }

            $row['total_value'] = $missing === 0 ? round($total, 2) : null;
            $results[] = $row;
        }

        return $results;
    }

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

    public function subjectBreakdownForStudent(Student $student, ?int $semester = null): array
    {
        if (!$student->section_id) {
            return [];
        }

        $query = GradeSubmission::where('status', 'approved')
            ->where('section_id', $student->section_id);

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

    public function averageForStudent(Student $student): ?float
    {
        return $this->currentSemesterBreakdown($student)['average_grade_100'];
    }

    public function recentActivityForTeacher(Teacher $teacher, int $limit = 10): array
    {
        $activities = collect();

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
                'description' => $submission->teacherAssignment->section->schoolClass->name
                    . ' - ' . $submission->teacherAssignment->section->name
                    . ' (' . $semesterLabel . ')',
                'date' => $submission->updated_at,
            ]);
        }

        return $activities->sortByDesc('date')->take($limit)->values()->toArray();
    }

// المقررات الدراسية لشعبة الطالب (اسم المادة + المعلم + الساعات الأسبوعية، بدون علامة)
public function coursesForStudent(Student $student): array
{
    if (!$student->section_id) {
        return [];
    }

    $assignments = TeacherAssignment::where('section_id', $student->section_id)
        ->with('subject:id,name', 'teacher.user:id,user_name')
        ->get();

    $result = [];

    foreach ($assignments as $assignment) {
        $weeklyHours = \App\Models\WeeklySchedule::where('teacher_assignment_id', $assignment->id)->count();

        $result[] = [
            'subject' => $assignment->subject->name,
            'teacher_name' => $assignment->teacher->user->user_name,
            'weekly_hours' => $weeklyHours,
        ];
    }

    return $result;
}
}
