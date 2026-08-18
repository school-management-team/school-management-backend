<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklySchedule extends Model
{
    protected $fillable = [
        'teacher_id', 'teacher_assignment_id', 'section_id', 'day_of_week',
        'period_number', 'start_time', 'end_time', 'type',
    ];

    protected $appends = ['subject_name', 'section_name', 'class_name'];

    /**
     * section_id مشتق من التكليف دائماً — منزامنه هون حتى ما يقدر أي مسار
     * (كنترولر أو سيدر) يخلّي الشعبة المخزّنة مختلفة عن شعبة التكليف.
     */
    protected static function booted(): void
    {
        static::saving(function (self $schedule) {
            $schedule->section_id = $schedule->teacher_assignment_id
                ? TeacherAssignment::whereKey($schedule->teacher_assignment_id)->value('section_id')
                : null;
        });
    }

    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function teacherAssignment(): BelongsTo { return $this->belongsTo(TeacherAssignment::class); }
    public function lessonPlans(): HasMany { return $this->hasMany(LessonPlan::class); }
    public function substitutions(): HasMany { return $this->hasMany(LessonSubstitution::class); }

    public function getStatusAttribute(): string
    {
        $now = now()->format('H:i:s');

        if ($now < $this->start_time) return 'upcoming';
        if ($now > $this->end_time) return 'completed';

        return 'now';
    }

    public function getSubjectNameAttribute(): ?string { return $this->teacherAssignment?->subject?->name; }
    public function getSectionNameAttribute(): ?string { return $this->teacherAssignment?->section?->name; }
    public function getClassNameAttribute(): ?string { return $this->teacherAssignment?->section?->schoolClass?->name; }
}
