<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;



class Supervisor extends Model
{
     use HasFactory;
        protected $fillable = [
        'educational_qualification',
        'specialization',
        'bio',
        'cv_file',
        'user_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }


    public function recordedAttendances(): HasMany { return $this->hasMany(Attendance::class); }
    public function announcements(): HasMany { return $this->hasMany(Announcement::class); }
}
