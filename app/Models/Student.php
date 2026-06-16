<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory , SoftDeletes;

    protected $fillable = [
        'student_number',
        'student_name', 'father_name', 'mother_name',
        'birth_date', 'gender', 'education_level', 'grade',
        'status', 'enrollment_date'
    ];

    protected $casts = [
        'birth_date' => 'date',
        'enrollment_date' => 'date',

    ];

    public function user()
    {
        return $this->hasOne(User::class, 'student_id');
    }
    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student')
        ->withPivot('relationship', 'is_primary')
        ->withTimestamps();
    }


}
