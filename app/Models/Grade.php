<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grade extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'teacher_assignment_id', 'type', 'semester', 'value', 'status',
    ];

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function teacherAssignment(): BelongsTo { return $this->belongsTo(TeacherAssignment::class); }
}
