<?php

namespace App\Models;


use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;
    protected $table = 'attendance';


    protected $fillable = [
        'student_id',
        'section_id',
        'supervisor_id',
        'date',
        'status',
        'excuse',
        'left_at',
    ];

    protected $casts = ['date' => 'date'];

    /** بلا وقت — لتشتغل updateOrCreate على المفتاح (student_id, date) */
    public function setDateAttribute($value)
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    /** الوقت دايماً H:i:s — MySQL بتطبّعه لحالها وSQLite بتخزّنه متل ما إجا */
    public function setLeftAtAttribute($value)
    {
        if ($value === null) {
            $this->attributes['left_at'] = null;
            return;
        }

        $this->attributes['left_at'] = Carbon::parse($value)->format('H:i:s');
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function supervisor(): BelongsTo { return $this->belongsTo(supervisor::class); }

    public function scopeForDate($query, $date) { return $query->whereDate('date', $date); }
    public function scopeAbsentOrLate($query) { return $query->whereIn('status', ['absent', 'late']); }

    /** كل ما هو أقل من حضور كامل — هذا اللي يهم ولي الأمر */
    public function scopeConcerns($query)
    {
        return $query->whereIn('status', config('school.attendance_concerns'));
    }
}
