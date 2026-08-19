<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use HasFactory;
    protected $fillable = [
        'teacher_assignment_id', 'title', 'description',
        'due_date', 'max_grade', 'attachment_path', 'attachment_link',
    ];

    protected $casts = ['due_date' => 'date'];
    protected $appends = ['attachment_url'];

    public function teacherAssignment(): BelongsTo
    {
        return $this->belongsTo(TeacherAssignment::class);
    }
    // app/Models/Assignment.php — أضف هذا التابع
    public function studentStatuses(): HasMany
    {
        return $this->hasMany(StudentAssignmentStatus::class);
    }


public function getAttachmentUrlAttribute(): ?string
{
    return $this->attachment_path ? asset('storage/' . $this->attachment_path) : null;
}
}
