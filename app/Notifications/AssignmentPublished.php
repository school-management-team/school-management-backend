<?php

namespace App\Notifications;

use App\Models\Assignment;

/** من المعلم إلى طلاب شعبته: واجب جديد */
class AssignmentPublished extends BaseNotification
{
    public function __construct(protected Assignment $assignment) {}

    public function type(): string
    {
        return 'assignment.published';
    }

    public function message(): string
    {
        $subject = $this->assignment->teacherAssignment?->subject?->name ?? 'مادة';
        $due = $this->assignment->due_date;

        if ($due) {
            return "واجب جديد في {$subject}: {$this->assignment->title} — التسليم {$due->toDateString()}";
        }

        return "واجب جديد في {$subject}: {$this->assignment->title}";
    }

    public function payload(): array
    {
        $teacherAssignment = $this->assignment->teacherAssignment;

        return [
            'assignment_id' => $this->assignment->id,
            'title' => $this->assignment->title,
            'due_date' => $this->assignment->due_date?->toDateString(),
            'max_grade' => $this->assignment->max_grade,
            'subject' => $teacherAssignment?->subject?->name,
            'section_id' => $teacherAssignment?->section_id,
            'teacher_name' => $teacherAssignment?->teacher?->user?->user_name,
        ];
    }
}
