<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'student_number',
        'father_name',
        'mother_name',
        'education_level',
        'school_class',
        'enrollment_date',
        'user_id',
    ];

    protected $casts = [
        'enrollment_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student')
            ->withTimestamps();
    }
}
