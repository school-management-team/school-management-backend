<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Models\TeacherAssignment;

class ReportCardService
{
    /**
     * كشف علامات كامل لطالب واحد بفصل واحد.
     *
     * $finalOnly = true → لولي الأمر: المواد غير المعتمدة بتظهر بالاسم
     * وحالتها بس، بدون قيم، لأنها لسا قابلة للتعديل.
     */
    public function forStudent(Student $student, int $semester, bool $finalOnly = false): array
    {
        $components = config('school.grade_components');

        // مادة وحدة = صف واحد بالكشف، حتى لو أكتر من معلم بياخدها لهالشعبة
        $assignments = TeacherAssignment::where('section_id', $student->section_id)
            ->with('subject:id,name,passing_grade', 'teacher.user:id,user_name')
            ->get()
            ->groupBy('subject_id');

        $submissions = GradeSubmission::where('section_id', $student->section_id)
            ->where('semester', $semester)
            ->get()
            ->keyBy('subject_id');

        $grades = Grade::where('student_id', $student->id)
            ->where('semester', $semester)
            ->get()
            ->groupBy('subject_id');

        $subjects = [];

        foreach ($assignments as $subjectId => $subjectAssignments) {
            $assignment = $subjectAssignments->first();
            $subjectGrades = $grades->get($subjectId, collect());

            $submission = $submissions->get($subjectId);
            $status = $submission ? $submission->status : 'draft';
            $isFinal = $status === 'approved';

            // هل منخفي القيم عن ولي الأمر؟
            $hideValues = $finalOnly && !$isFinal;

            $breakdown = [];
            $total = 0;
            $missing = 0;

            foreach ($components as $type => $component) {
                $grade = $subjectGrades->firstWhere('type', $type);
                $value = $grade ? $grade->value : null;

                if ($value === null) {
                    $missing++;
                }

                $total += $value * $component['weight'] / 100;

                $weighted = null;

                if ($value !== null) {
                    $weighted = round($value * $component['weight'] / 100, 2);
                }

                $breakdown[] = [
                    'type' => $type,
                    'label' => $component['label'],
                    'weight' => $component['weight'],
                    'value' => $hideValues ? null : $value,
                    'weighted_value' => $hideValues ? null : $weighted,
                ];
            }

            $isComplete = $missing === 0 && !$hideValues;
            $passingGrade = $assignment->subject ? $assignment->subject->passing_grade : null;

            // المجموع بيظهر بس إذا المادة مكتملة (وغير محجوبة)
            $totalValue = null;
            $passed = null;

            if ($isComplete) {
                $totalValue = round($total, 2);

                if ($passingGrade !== null) {
                    $passed = $totalValue >= $passingGrade;
                }
            }

            // ممكن يكونوا أكتر من معلم لنفس المادة — بس المحصّلة وحدة
            $teachers = [];

            foreach ($subjectAssignments as $subjectAssignment) {
                $name = $subjectAssignment->teacher ? $subjectAssignment->teacher->user->user_name : null;

                if ($name !== null && !in_array($name, $teachers)) {
                    $teachers[] = $name;
                }
            }

            $subjects[] = [
                'subject_id' => $subjectId,
                'subject' => $assignment->subject ? $assignment->subject->name : null,
                'teachers' => $teachers,
                'components' => $breakdown,
                'total_value' => $totalValue,
                'passing_grade' => $passingGrade,
                'passed' => $passed,
                'status' => $status,
                'is_final' => $isFinal,
                'is_complete' => $isComplete,
                'missing_components' => $missing,
            ];
        }

        return [
            'semester' => $semester,
            'subjects' => $subjects,
            'summary' => $this->summarize($subjects),
        ];
    }

    // كشف الفصلين مع بعض
    public function forStudentAllSemesters(Student $student, bool $finalOnly = false): array
    {
        $result = [];

        foreach (config('school.semesters') as $semester) {
            $result[] = $this->forStudent($student, $semester, $finalOnly);
        }

        return $result;
    }

    // بيانات الطالب الترويسية للكشف
    public function studentHeader(Student $student): array
    {
        $student->loadMissing('user:id,user_name', 'section.schoolClass', 'schoolClass');

        $className = null;

        if ($student->schoolClass) {
            $className = $student->schoolClass->name;
        } elseif ($student->section && $student->section->schoolClass) {
            $className = $student->section->schoolClass->name;
        }

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->user ? $student->user->user_name : null,
            'father_name' => $student->father_name,
            'mother_name' => $student->mother_name,
            'class_name' => $className,
            'section_name' => $student->section ? $student->section->name : null,
            'enrollment_date' => $student->enrollment_date ? $student->enrollment_date->toDateString() : null,
        ];
    }

    /**
     * المعدّل بينحسب من المواد المعتمدة والمكتملة بس — مادة لسا مرصودة
     * أو مرفوضة ما بتدخل بالحساب حتى ما يطلع معدّل مضلّل.
     */
    private function summarize(array $subjects): array
    {
        $countedTotals = [];
        $passedCount = 0;
        $failedCount = 0;
        $pendingCount = 0;

        foreach ($subjects as $subject) {
            if (!$subject['is_final']) {
                $pendingCount++;
            }

            if (!$subject['is_final'] || !$subject['is_complete']) {
                continue;
            }

            $countedTotals[] = $subject['total_value'];

            if ($subject['passed'] === true) {
                $passedCount++;
            } elseif ($subject['passed'] === false) {
                $failedCount++;
            }
        }

        $average = null;

        if (count($countedTotals) > 0) {
            $average = round(array_sum($countedTotals) / count($countedTotals), 2);
        }

        return [
            'total_subjects' => count($subjects),
            'counted_subjects' => count($countedTotals),
            'pending_subjects' => $pendingCount,
            'passed_subjects' => $passedCount,
            'failed_subjects' => $failedCount,
            'average' => $average,
            'is_complete' => count($subjects) > 0 && count($countedTotals) === count($subjects),
        ];
    }
}
