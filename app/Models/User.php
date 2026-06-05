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
        'is_active',
        'email_verified_at',
        'last_login_at',
        'last_login_ip',
        'admin_id',
        'supervisor_id',
        'teacher_id',
        'student_id',
        'guardian_id',
        'verification_code',
        'verification_expires_at',
        'password_changed_at',
        'failed_attempts',
        'locked_until',
        'remember_token',
        'remember_expires_at',
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
    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }
    //الأدوار
    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }
    public function isGuardian(): bool
    {
    return $this->role === 'guardian';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }
    public function isAdmin(): bool
    {
    return $this->role === 'admin';
    }



    public function profile()
    {
    if ($this->isAdmin()) return $this->admin;
    if ($this->isTeacher()) return $this->teacher;
    if ($this->isStudent()) return $this->student;
    if ($this->isGuardian()) return $this->guardian;
    return null;
    }


    //  إدارة التوكنات




    //  الحماية من محاولات الدخول
    public function recordFailedAttempt(): void
    {
        $this->increment('failed_attempts');

        if ($this->failed_attempts >= 5) {
            $this->update(['locked_until' => now()->addMinutes(30)]);
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
        if($this->isGuardian()){
            $this->guardian()->update(['status'=>'pending']);
        }


        $this->update([
            'email_verified_at' =>$this->email_verified_at ?? now(), //اذا لم يكن مفعل
            'verification_code' => null,
            'verification_expires_at' => null
        ]);

        return true;
    }

    public function isVerified(): bool
    {
        return !is_null($this->email_verified_at);
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
