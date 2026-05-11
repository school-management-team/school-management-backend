<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_name', 'father_name', 'mother_name', 'last_name', 'student_id',
        'birth_date', 'gender', 'education_level', 'grade', 'section',
        'address', 'guardian_phone', 'guardian_email', 'guardian_relation',
        'health_status', 'legal_document_path', 'bus_id',
        'status', 'enrollment_date', 'wallet_balance'
    ];

    protected $casts = [
        'birth_date' => 'date',
        'enrollment_date' => 'date',
        'wallet_balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'student_id');
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->father_name . ' ' . $this->last_name;
    }
}
