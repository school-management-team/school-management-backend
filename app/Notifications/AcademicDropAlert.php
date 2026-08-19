<?php

namespace App\Notifications;

use App\Models\Student;
use App\Models\Subject;

/** من الموجّه إلى معلمي الطالب وأوليائه: تراجع بالمستوى */
class AcademicDropAlert extends BaseNotification
{
    public function __construct(
        protected Student $student,
        protected Subject $subject,
        protected float $previousValue,
        protected float $currentValue,
        protected ?string $note = null
    ) {}

    public function type(): string
    {
        return 'student.academic_drop';
    }

    public function message(): string
    {
        $name = $this->student->user?->user_name ?? 'الطالب';
        $drop = round($this->previousValue - $this->currentValue, 2);

        return "تراجعت علامة {$name} في {$this->subject->name} بمقدار {$drop} "
            ."(من {$this->previousValue} إلى {$this->currentValue})";
    }

    public function payload(): array
    {
        return [
            'student_id' => $this->student->id,
            'student_name' => $this->student->user?->user_name,
            'student_number' => $this->student->student_number,
            'section_id' => $this->student->section_id,
            'subject_id' => $this->subject->id,
            'subject' => $this->subject->name,
            'previous_value' => $this->previousValue,
            'current_value' => $this->currentValue,
            'drop' => round($this->previousValue - $this->currentValue, 2),
            'note' => $this->note,
        ];
    }
}
