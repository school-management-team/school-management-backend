<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id', 'stage_id',
        'cv',
        'legal_document_path',
        'user_id',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function assignments(): HasMany { return $this->hasMany(TeacherAssignment::class); }
    public function questions(): HasMany { return $this->hasMany(Question::class); }
    public function weeklySchedules(): HasMany { return $this->hasMany(WeeklySchedule::class); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function stage(): BelongsTo { return $this->belongsTo(Stage::class); }
    public function attendances(): HasMany { return $this->hasMany(TeacherAttendance::class); }

    /** الحصص يلي غاب عنها وانعطت لغيره */
    public function coveredLessons(): HasMany { return $this->hasMany(LessonSubstitution::class, 'absent_teacher_id'); }

    /** الحصص يلي عوّضها عن غيره */
    public function substitutions(): HasMany { return $this->hasMany(LessonSubstitution::class, 'substitute_teacher_id'); }
}
