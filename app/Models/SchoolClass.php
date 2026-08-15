<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    protected $table = 'classes';
    protected $fillable = ['name', 'grade_order', 'stage_id'];

    public function stage(): BelongsTo { return $this->belongsTo(Stage::class); }
    public function sections(): HasMany { return $this->hasMany(Section::class, 'class_id'); }
    public function students(): HasMany { return $this->hasMany(Student::class, 'class_id'); }
    public function questions(): HasMany { return $this->hasMany(Question::class, 'class_id'); }
}
