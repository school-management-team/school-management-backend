<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
use  SoftDeletes;
protected $fillable = [
    'guardian_name',
    'relationship',
    'number_of_children',
    'status',
];

public function students()
{
    return $this->belongsToMany(Student::class, 'guardian_student')
        ->withTimestamps();
}
}
