<?php
// app/Models/Teacher.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_name', 'middle_name', 'last_name', 'teacher_id', 'national_id',
        'birth_date', 'gender', 'address', 'health_status', 'specialization',
        'education_level', 'high_school_branch', 'is_class_teacher',
        'years_of_experience', 'weekly_hours', 'hire_date',
        'cv_path', 'legal_document_path', 'employment_status', 'rating'
    ];

    protected $casts = [
        'birth_date' => 'date',
        'hire_date' => 'date',
        'is_class_teacher' => 'boolean',
        'rating' => 'decimal:2',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'teacher_id');
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}