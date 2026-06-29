<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'user_name',
        'email',
        'password',
        'role',
        'phone',
        'gender',
        'birth_date',
        'status',
        'verification_code',
        'verification_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'verification_expires_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'locked_until' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function teacher()
    {
        return $this->hasOne(Teacher::class);
    }

    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function guardian()
    {
        return $this->hasOne(Guardian::class);
    }
    public function supervisor()
    {
        return $this->hasOne(Supervisor::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }


    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function isGuardian(): bool
    {
        return $this->role === 'guardian';
    }


    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function profile()
    {
        if ($this->isTeacher()) {
            return $this->teacher;
        }

        if ($this->isStudent()) {
            return $this->student;
        }

        if ($this->isGuardian()) {
            return $this->guardian;
        }
        if($this->isSupervisor()){
            return $this->supervisor;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Login Security
    |--------------------------------------------------------------------------
    */

    public function recordFailedAttempt(): void
    {
        $this->increment('failed_attempts');

        if ($this->failed_attempts >= 5) {
            $this->update([
                'locked_until' => now()->addMinutes(30),
            ]);
        }
    }

    public function resetFailedAttempts(): void
    {
        $this->update([
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    public function isLocked(): bool
    {
        if (!$this->locked_until) {
            return false;
        }

        if ($this->locked_until->isPast()) {
            $this->update([
                'locked_until' => null,
            ]);

            return false;
        }

        return true;
    }

    public function getLockRemainingMinutes(): int
    {
        return max(
            0,
            now()->diffInMinutes($this->locked_until, false)
        );
    }

    public function getRemainingAttempts(): int
    {
        return max(0, 5 - $this->failed_attempts);
    }

    /*
    |--------------------------------------------------------------------------
    | Email Verification
    |--------------------------------------------------------------------------
    */

    public function generateVerificationCode(): string
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->update([
            'verification_code' => $code,
            'verification_expires_at' => now()->addMinutes(30),
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

        $this->update([
            'email_verified_at' => $this->email_verified_at ?? now(),
            'verification_code' => null,
            'verification_expires_at' => null,
        ]);

        return true;
    }



    public function isVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }



    public function scopeActive($query)
    {
        return $query->where('status', 'active');
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


    //Student Number

    public static function getNextStudentNumberForGrade(string $class): string
    {
        $ranges = config('school.student_number_ranges');

        if (!isset($ranges[$class])) {
            throw new \Exception("الصف {$class} غير معرف");
        }

        $start = $ranges[$class]['start'];
        $end = $ranges[$class]['end'];
        $lastStudent = Student::where('school_class', $class)
            ->whereNotNull('student_number')
            ->orderByDesc('student_number')
            ->first();

        if ($lastStudent) {
            $number = (int) $lastStudent->student_number + 1;

            if ($number > $end) {
                throw new \Exception("نفدت الأرقام لهذا الصف");
            }

            return (string) $number;
        }

        return (string) $start;
    }
}
