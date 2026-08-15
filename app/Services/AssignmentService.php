<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Teacher;
use App\Models\TeacherAssignment;


class AssignmentService
{
    // المواد اللي يدرّسها المعلم (لتعبئة قائمة "اختر المادة")
    public function subjectsForTeacher(Teacher $teacher)
    {
        return $teacher->assignments()
            ->with('subject:id,name')
            ->get()
            ->pluck('subject')
            ->unique('id')
            ->values();
    }

    // الشعب اللي يدرّس فيها المعلم مادة معينة (لتعبئة "الصف والشعبة" بعد اختيار المادة)
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

    // التحقق من أن المعلم فعلاً يدرّس هذه المادة لهذه الشعبة
    public function findTeacherAssignment(Teacher $teacher, int $subjectId, int $sectionId): ?TeacherAssignment
    {
        return TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('subject_id', $subjectId)
            ->where('section_id', $sectionId)
            ->first();
    }

    public function create(TeacherAssignment $teacherAssignment, array $data): Assignment
    {
        return Assignment::create([
            'teacher_assignment_id' => $teacherAssignment->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'max_grade' => $data['max_grade'] ?? 100,
            'attachment_path' => $data['attachment_path'] ?? null,
            'attachment_link' => $data['attachment_link'] ?? null,
        ]);
    }

    // كل الواجبات اللي أنشأها المعلم
    public function list(Teacher $teacher, array $filters)
    {
        $query = Assignment::whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($teacher) {
            $teacherAssignmentQuery->where('teacher_id', $teacher->id);
        });

        if (!empty($filters['section_id'])) {
            $query->whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($filters) {
                $teacherAssignmentQuery->where('section_id', $filters['section_id']);
            });
        }

        if (!empty($filters['subject_id'])) {
            $query->whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($filters) {
                $teacherAssignmentQuery->where('subject_id', $filters['subject_id']);
            });
        }

        return $query->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }
}
