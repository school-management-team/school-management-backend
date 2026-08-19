<?php

namespace App\Notifications;

use App\Models\Section;
use App\Models\Teacher;

/** من المعلم إلى طلاب شعبته: تنبيه أو إعلان عام */
class ClassAnnouncement extends BaseNotification
{
    public function __construct(
        protected Teacher $teacher,
        protected Section $section,
        protected string $title,
        protected string $body
    ) {}

    public function type(): string
    {
        return 'class.announcement';
    }

    public function title(): string
    {
        return $this->title;
    }

    public function message(): string
    {
        return $this->body;
    }

    public function payload(): array
    {
        return [
            'section_id' => $this->section->id,
            'section_name' => $this->section->name,
            'class_name' => $this->section->schoolClass?->name,
            'teacher_id' => $this->teacher->id,
            'teacher_name' => $this->teacher->user?->user_name,
            'subject' => $this->teacher->subject?->name,
        ];
    }
}
