<?php

namespace App\Notifications;

use App\Models\Student;
use Carbon\Carbon;

/** من الموجّه إلى ولي الأمر (والمعلم عند اللزوم): موعد اجتماع */
class ParentMeetingScheduled extends BaseNotification
{
    public function __construct(
        protected Student $student,
        protected string $meetingDate,
        protected string $meetingTime,
        protected ?string $location = null,
        protected ?string $reason = null
    ) {}

    public function type(): string
    {
        return 'meeting.scheduled';
    }

    public function message(): string
    {
        $name = $this->student->user?->user_name ?? 'الطالب';
        $date = Carbon::parse($this->meetingDate)->toDateString();

        $text = "اجتماع بخصوص {$name} يوم {$date} الساعة {$this->meetingTime}";

        if ($this->location) {
            $text .= " في {$this->location}";
        }

        return $text;
    }

    public function payload(): array
    {
        return [
            'student_id' => $this->student->id,
            'student_name' => $this->student->user?->user_name,
            'meeting_date' => Carbon::parse($this->meetingDate)->toDateString(),
            'meeting_time' => $this->meetingTime,
            'location' => $this->location,
            'reason' => $this->reason,
        ];
    }
}
