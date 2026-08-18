<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{

    use HasFactory;

    protected $fillable = ['name', 'class_id','capacity'];


    public function schoolClass(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id'); }
    public function students(): HasMany { return $this->hasMany(Student::class); }
    public function teacherAssignments(): HasMany { return $this->hasMany(TeacherAssignment::class); }
}
