<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonSubstitution extends Model
{
    protected $fillable = [
        'weekly_schedule_id',
        'absent_teacher_id',
        'substitute_teacher_id',
        'supervisor_id',
        'date',
        'day_of_week',
        'period_number',
        'status',
        'note',
    ];

    protected $casts = ['date' => 'date'];

    /** بلا وقت — لتشتغل updateOrCreate على المفتاح (weekly_schedule_id, date) */
    public function setDateAttribute($value)
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    public function weeklySchedule(): BelongsTo { return $this->belongsTo(WeeklySchedule::class); }
    public function absentTeacher(): BelongsTo { return $this->belongsTo(Teacher::class, 'absent_teacher_id'); }
    public function substituteTeacher(): BelongsTo { return $this->belongsTo(Teacher::class, 'substitute_teacher_id'); }
    public function supervisor(): BelongsTo { return $this->belongsTo(Supervisor::class); }

    public function scopeForDate($query, $date) { return $query->whereDate('date', $date); }

    /** التعويضات الفعّالة (المُلغاة ما بتحجز البديل) */
    public function scopeActive($query) { return $query->where('status', '!=', 'cancelled'); }
}
