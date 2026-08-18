<?php

namespace App\Models;


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
    ];

    protected $casts = ['date' => 'date'];

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function supervisor(): BelongsTo { return $this->belongsTo(supervisor::class); }

    public function scopeForDate($query, $date) { return $query->whereDate('date', $date); }
    public function scopeAbsentOrLate($query) { return $query->whereIn('status', ['absent', 'late']); }
}
