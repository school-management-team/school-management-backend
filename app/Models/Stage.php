<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    use HasFactory;
     protected $fillable = ['name'];
    public function classes(): HasMany { return $this->hasMany(SchoolClass::class); }
    public function subjects() { return $this->belongsToMany(Subject::class, 'stage_subject');}

    public function getTrackLabelAttribute(): ?string
{
    return match ($this->name) {
        'high_scientific' => 'المسار العلمي',
        'high_literary' => 'المسار الأدبي',
        default => null,
    };
}
}
