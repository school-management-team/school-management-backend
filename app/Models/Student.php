<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_number',
        'father_name',
        'mother_name',
        'enrollment_date',
        'class_id',
        'section_id',
        'user_id',
    ];

    protected $casts = ['enrollment_date' => 'date'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student')->withTimestamps();
    }
    public function schoolClass(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id'); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function attendances(): HasMany { return $this->hasMany(Attendance::class); }
    public function grades(): HasMany { return $this->hasMany(Grade::class); }
    public function fees(): HasMany { return $this->hasMany(StudentFee::class); }
}
