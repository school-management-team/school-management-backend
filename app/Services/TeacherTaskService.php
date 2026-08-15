<?php
namespace App\Services;

use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TeacherTask;

class TeacherTaskService
{
    public function findTeacherAssignment(Teacher $teacher, int $subjectId, int $sectionId): ?TeacherAssignment
    {
        return TeacherAssignment::where('teacher_id', $teacher->id)
            ->where('subject_id', $subjectId)
            ->where('section_id', $sectionId)
            ->first();
    }

    public function create(TeacherAssignment $teacherAssignment, array $data): TeacherTask
    {
        return TeacherTask::create([
            'teacher_assignment_id' => $teacherAssignment->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'is_important' => $data['is_important'] ?? false,
            'due_date' => $data['due_date'],
        ]);
    }

    public function list(Teacher $teacher, array $filters)
    {
        $query = TeacherTask::whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($teacher) {
            $teacherAssignmentQuery->where('teacher_id', $teacher->id);
        });

if (array_key_exists('is_important', $filters)) {
    $query->where('is_important', $filters['is_important']);
}

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['subject_id'])) {
            $query->whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($filters) {
                $teacherAssignmentQuery->where('subject_id', $filters['subject_id']);
            });
        }

        return $query->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
            ->orderBy('due_date', 'asc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function upcoming(Teacher $teacher, int $limit = 5)
    {
        return TeacherTask::whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($teacher) {
            $teacherAssignmentQuery->where('teacher_id', $teacher->id);
        })
            ->where('due_date', '>=', now()->toDateString())
            ->with('teacherAssignment.subject:id,name', 'teacherAssignment.section.schoolClass:id,name')
            ->orderBy('due_date', 'asc')
            ->limit($limit)
            ->get();
    }

    public function update(TeacherTask $task, array $data): TeacherTask
    {
        $task->update($data);
        return $task;
    }

    public function markStatus(TeacherTask $task, string $status): TeacherTask
    {
        $task->update(['status' => $status]);
        return $task;
    }

    // نسبة إنجاز المهام (لبطاقة "إنجازك اليوم")
    public function progress(Teacher $teacher): array
    {
        $today = now()->toDateString();

        $total = TeacherTask::whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($teacher) {
            $teacherAssignmentQuery->where('teacher_id', $teacher->id);
        })->where('due_date', $today)->count();

        $completed = TeacherTask::whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($teacher) {
            $teacherAssignmentQuery->where('teacher_id', $teacher->id);
        })->where('due_date', $today)->where('status', 'completed')->count();

        $percentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => $percentage,
        ];
    }

    // زر "تسليم" = تحديد المهمة كمكتملة
    public function submit(TeacherTask $task): TeacherTask
    {
        $task->update(['status' => 'completed']);
        return $task;
    }

    // عدد المهام المعلقة (قيد التنفيذ) — لبطاقة "مهام معلقة" بلوحة التحكم
    public function pendingCount(Teacher $teacher): int
    {
        return TeacherTask::whereHas('teacherAssignment', function ($teacherAssignmentQuery) use ($teacher) {
            $teacherAssignmentQuery->where('teacher_id', $teacher->id);
        })->where('status', 'in_progress')->count();
    }
}
