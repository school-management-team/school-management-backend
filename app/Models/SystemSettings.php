<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'registration_locked',
        'locked_at',
        'lock_reason',
    ];

    protected $casts = [
        'registration_locked' => 'boolean',
        'locked_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
