<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherTask extends Model
{
    protected $fillable = ['teacher_assignment_id', 'title', 'description', 'is_important', 'status', 'due_date'];

    protected $casts = [
        'is_important' => 'boolean',
        'due_date' => 'date',
    ];

    protected $appends = ['class_name', 'section_name', 'subject_name'];

    public function teacherAssignment(): BelongsTo
    {
        return $this->belongsTo(TeacherAssignment::class);
    }

    public function getClassNameAttribute(): ?string
    {
        return $this->teacherAssignment?->section?->schoolClass?->name;
    }

    public function getSectionNameAttribute(): ?string
    {
        return $this->teacherAssignment?->section?->name;
    }

    public function getSubjectNameAttribute(): ?string
    {
        return $this->teacherAssignment?->subject?->name;
    }
}
