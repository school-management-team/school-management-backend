<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherAssignment extends Model
{
    use HasFactory;
    protected $fillable = ['teacher_id', 'subject_id', 'section_id'];

    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function grades(): HasMany { return $this->hasMany(Grade::class); }
    public function assignments(): HasMany { return $this->hasMany(Assignment::class); }
    public function tasks(): HasMany { return $this->hasMany(TeacherTask::class); }
    public function weeklySchedules(): HasMany { return $this->hasMany(WeeklySchedule::class); }
}
