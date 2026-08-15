<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeSubmission extends Model
{
    protected $fillable = ['teacher_assignment_id', 'semester', 'status', 'approved_by'];

    public function teacherAssignment(): BelongsTo { return $this->belongsTo(TeacherAssignment::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
