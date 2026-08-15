<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
     protected $fillable = ['name'];
    public function classes(): HasMany { return $this->hasMany(SchoolClass::class); }
    public function subjects() { return $this->belongsToMany(Subject::class, 'stage_subject');}
}
