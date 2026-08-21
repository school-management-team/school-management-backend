<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonPlan extends Model
{
    protected $fillable = ['weekly_schedule_id', 'date', 'content'];
    protected $casts = ['date' => 'date'];
    
    public function setDateAttribute($value)
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    public function weeklySchedule(): BelongsTo
    {
        return $this->belongsTo(WeeklySchedule::class);
    }
}
