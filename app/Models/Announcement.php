<?php

// app/Models/Announcement.php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = [
        'supervisor_id', 'title', 'description', 'type',
        'is_important', 'date', 'end_date', 'image_path', 'attachment_path',
    ];

    protected $casts = [
        'is_important' => 'boolean',
        'date' => 'date',
        'end_date' => 'date',
    ];

    protected $appends = ['days_count', 'is_multi_day'];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Supervisor::class);
    }

    /** بلا وقت — العمودين من نوع DATE */
    public function setDateAttribute($value)
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    public function setEndDateAttribute($value)
    {
        if ($value === null) {
            $this->attributes['end_date'] = null;
            return;
        }

        $this->attributes['end_date'] = Carbon::parse($value)->toDateString();
    }

    public function scopeHolidays($query)
    {
        return $query->where('type', 'holiday');
    }

    /**
     * الأحداث اللي بتغطي تاريخ معيّن.
     * الحدث بيغطي التاريخ إذا: بلّش قبله أو فيه، وخلص فيه أو بعده.
     * لما end_date تكون فاضية معناها الحدث يوم واحد بس.
     */
    public function scopeCovering($query, $date)
    {
        $query->whereDate('date', '<=', $date);

        $query->where(function ($group) use ($date) {
            // حدث يوم واحد: تاريخه هو نفسه اليوم المطلوب
            $group->where(function ($single) use ($date) {
                $single->whereNull('end_date')->whereDate('date', '>=', $date);
            });

            // حدث ممتد: نهايته بعد اليوم المطلوب أو فيه
            $group->orWhere(function ($range) use ($date) {
                $range->whereNotNull('end_date')->whereDate('end_date', '>=', $date);
            });
        });

        return $query;
    }

    /** الأحداث اللي بتتقاطع مع مدى تاريخين */
    public function scopeOverlapping($query, $from, $to)
    {
        $query->whereDate('date', '<=', $to);

        $query->where(function ($group) use ($from) {
            $group->where(function ($single) use ($from) {
                $single->whereNull('end_date')->whereDate('date', '>=', $from);
            });

            $group->orWhere(function ($range) use ($from) {
                $range->whereNotNull('end_date')->whereDate('end_date', '>=', $from);
            });
        });

        return $query;
    }

    public function getIsMultiDayAttribute(): bool
    {
        if ($this->end_date === null) {
            return false;
        }

        return !$this->end_date->isSameDay($this->date);
    }

    public function getDaysCountAttribute(): int
    {
        if ($this->end_date === null) {
            return 1;
        }

        return (int) $this->date->diffInDays($this->end_date) + 1;
    }
}
