<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'passing_grade', 'description'];
    public function teacherAssignments(): HasMany { return $this->hasMany(TeacherAssignment::class); }
    public function questions(): HasMany { return $this->hasMany(Question::class); }
    public function stages() { return $this->belongsToMany(Stage::class, 'stage_subject');}
}
