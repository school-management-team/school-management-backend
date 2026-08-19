<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeSubmission extends Model
{


    protected $fillable = [
        'teacher_assignment_id', 'subject_id', 'section_id',
        'semester', 'status', 'approved_by',
    ];


    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }

    /** مرجع لمين رفع الكشف — الدفتر مفتاحه (شعبة + مادة) */
    public function teacherAssignment(): BelongsTo { return $this->belongsTo(TeacherAssignment::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
