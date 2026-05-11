<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;


class User extends Authenticatable implements WebAuthnAuthenticatable
{
    use  HasApiTokens;
    use HasFactory, Notifiable, SoftDeletes;
     use WebAuthnAuthentication;

    protected $fillable = [
        'email',
        'password',
        'role',
        'phone',
        'device_token',
        'is_active',
        'email_verified_at',
        'last_login_at',
        'last_login_ip',
        'teacher_id',
        'student_id',
        'verification_code',
        'verification_expires_at',
        'password_changed_at',
        'failed_attempts',
        'locked_until',
        'active_tokens',
        'force_logout',
        'force_logout_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'verification_expires_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'locked_until' => 'datetime',
        'force_logout' => 'boolean',
        'force_logout_at' => 'datetime',
        'active_tokens' => 'array',
    ];

    // العلاقات
    public function teacher()
    {
        return $this->belongsTo(Teacher::class);


    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    //الأدوار
    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }
    public function isAdmin(): bool
{
    return $this->role === 'admin';
}


    public function getFullNameAttribute(): string
    {
        if ($this->isAdmin()) {
            return 'مدير النظام';
    }
        $profile = $this->profile();
        return $profile ? $profile->first_name . ' ' . $profile->last_name : 'مستخدم';
    }

    public function profile()
    {
        if ($this->isAdmin()) {
            return null;
        }
        if ($this->isTeacher()) return $this->teacher;
        if ($this->isStudent()) return $this->student;

        return null;
    }


    //  إدارة التوكنات
    public function logoutAllDevices(): void
    {
        $this->tokens()->delete();
        $this->update([
            'active_tokens' => null,
            'force_logout' => false,
            'force_logout_at' => null
        ]);
    }

    public function logoutOtherDevices(): void
    {
        $currentTokenId = request()->user()?->currentAccessToken()?->id;
        if ($currentTokenId) {
            $this->tokens()->where('id', '!=', $currentTokenId)->delete();
        }
    }

    //  تسجيل الخروج الإجباري
    public function checkForceLogout():bool
    {
        if (!$this->force_logout) {
            return false;
        }

        if ($this->password_changed_at && $this->force_logout_at) {
            return $this->force_logout_at->gt($this->password_changed_at);
        }

        return true;
    }

    public function addActiveToken(string $tokenId, array $info = []): void
    {
        $tokens = $this->active_tokens ?? [];
        $tokens[$tokenId] = [
            'device' => $info['device'] ?? 'Unknown',
            'device_type' => $info['device_type'] ?? 'web',
            'browser' => $info['browser'] ?? null,
            'os' => $info['os'] ?? null,
            'ip' => request()->ip(),
            'created_at' => now()->toDateTimeString(),
            'last_activity' => now()->toDateTimeString()
        ];

        if (count($tokens) > 10) {
            $tokens = array_slice($tokens, -10, 10, true);
        }

        $this->update(['active_tokens' => $tokens]);
    }

    public function getActiveDevices(): array
    {
        $devices = [];
        $currentTokenId = request()->user()?->currentAccessToken()?->id;

        foreach ($this->tokens as $token) {
            $tokenInfo = $this->active_tokens[$token->id] ?? null;

            $devices[] = [
                'id' => $token->id,
                'name' => $token->name,
                'device' => $tokenInfo['device'] ?? 'Unknown',
                'device_type' => $tokenInfo['device_type'] ?? 'web',
                'browser' => $tokenInfo['browser'] ?? null,
                'os' => $tokenInfo['os'] ?? null,
                'ip' => $tokenInfo['ip'] ?? null,
                'is_current' => $token->id === $currentTokenId,
                'last_activity' => $tokenInfo['last_activity'] ?? $token->created_at->toDateTimeString(),
                'created' => $token->created_at->diffForHumans()
            ];
        }

        return $devices;
    }



    //  الحماية من محاولات الدخول
    public function recordFailedAttempt(): void
    {
        $this->increment('failed_attempts');

        if ($this->failed_attempts >= 5) {
            $this->update(['locked_until' => now()->addMinutes(5)]);
        }
    }

    public function resetFailedAttempts(): void
    {
        $this->update([
            'failed_attempts' => 0,
            'locked_until' => null
        ]);
    }

    public function isLocked(): bool
    {
        if (!$this->locked_until) {
            return false;
        }

        if ($this->locked_until->isPast()) {
            $this->update(['locked_until' => null]);
            return false;
        }

        return true;
    }

    public function getLockRemainingMinutes(): int
    {
        return $this->locked_until?->diffInMinutes(now()) ?? 0;
    }

    public function getRemainingAttempts(): int
    {
        return max(0, 5 - $this->failed_attempts);
    }

    //  تفعيل البريد الإلكتروني
    public function generateVerificationCode(): string
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'verification_code' => $code,
            'verification_expires_at' => now()->addMinutes(30)
        ]);

        return $code;
    }

    public function verifyCode(string $code): bool
    {
        if (!$this->verification_code || !$this->verification_expires_at) {
            return false;
        }

        if ($this->verification_expires_at->isPast()) {
            return false;
        }

        if ($this->verification_code !== $code) {
            return false;
        }
        if($this->isStudent()){
            $this->student()->update(['status'=>'pending']);
        }

        if($this->isTeacher()){
            $this->teacher()->update(['status'=>'pending']);
        }


        $this->update([
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_expires_at' => null
        ]);

        return true;
    }

    public function isVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }


    //  تسجيل الدخول
    public function recordLogin(array $deviceInfo = []): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
            'device_token' => $deviceInfo['device_token'] ?? $this->device_token
        ]);
    }

    //  Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function scopeUnverified($query)
    {
        return $query->whereNull('email_verified_at');
    }

    public function scopeLocked($query)
    {
        return $query->where('locked_until', '>', now());
    }

}
