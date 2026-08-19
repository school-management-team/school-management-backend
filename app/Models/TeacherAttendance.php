<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAttendance extends Model
{
    protected $fillable = [
        'teacher_id',
        'supervisor_id',
        'date',
        'status',
        'excuse',
        'check_in_time',
    ];

    protected $casts = ['date' => 'date'];

    /**
     * منخزّن التاريخ Y-m-d بلا وقت. بدون هيك بينخزّن "2026-08-16 00:00:00"
     * وبيصير updateOrCreate(['date' => '2026-08-16']) ما بيلاقي الصف الموجود
     * فيحاول insert وبيضرب بالـ unique.
     */
    public function setDateAttribute($value)
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    /** الوقت دايماً H:i:s — MySQL بتطبّعه لحالها وSQLite بتخزّنه متل ما إجا */
    public function setCheckInTimeAttribute($value)
    {
        if ($value === null) {
            $this->attributes['check_in_time'] = null;
            return;
        }

        $this->attributes['check_in_time'] = Carbon::parse($value)->format('H:i:s');
    }

    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function supervisor(): BelongsTo { return $this->belongsTo(Supervisor::class); }

    public function scopeForDate($query, $date) { return $query->whereDate('date', $date); }

    /** موجود بالمدرسة: حاضر أو متأخر (المتأخر وصل فعلياً) */
    public function scopeAtSchool($query) { return $query->whereIn('status', ['present', 'late']); }

    /** غائب بعذر أو بدون عذر — حصصه بدها تعويض */
    public function scopeAway($query) { return $query->whereIn('status', ['absent', 'excused']); }
}
