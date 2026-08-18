<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grade extends Model
{
    use HasFactory;
public $timestamps = false;
    protected $fillable = [
        'student_id', 'teacher_assignment_id', 'subject_id', 'section_id',
        'type', 'semester', 'value', 'status',
    ];

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }

    /** مرجع لمين رصد العلامة — مش مفتاح الدفتر (الدفتر = شعبة + مادة) */
    public function teacherAssignment(): BelongsTo { return $this->belongsTo(TeacherAssignment::class); }
}
