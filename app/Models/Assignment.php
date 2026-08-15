<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    protected $fillable = [
        'teacher_assignment_id', 'title', 'description',
        'due_date', 'max_grade', 'attachment_path', 'attachment_link',
    ];

    protected $casts = ['due_date' => 'date'];

    public function teacherAssignment(): BelongsTo
    {
        return $this->belongsTo(TeacherAssignment::class);
    }
}
