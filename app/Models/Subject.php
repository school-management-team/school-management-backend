<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['name', 'passing_grade', 'description'];
    public function teacherAssignments(): HasMany { return $this->hasMany(TeacherAssignment::class); }
}
