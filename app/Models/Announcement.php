<?php

// app/Models/Announcement.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = [
        'supervisor_id', 'title', 'description', 'type',
        'is_important', 'date', 'image_path', 'attachment_path',
    ];

    protected $casts = [
        'is_important' => 'boolean',
        'date' => 'date',
    ];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Supervisor::class);
    }
}
