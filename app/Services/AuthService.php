<?php

namespace App\Services;

use App\Models\User;
use App\Models\LoginHistory;
use App\Models\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;


class AuthService
{
    
    // تسجيل الدخول 
     
    public function login(string $email, string $password, array $deviceInfo = []): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            //  تسجيل محاولة فاشلة (مستخدم غير موجود)
            LoginHistory::create([
                'user_id' => 0,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device_type' => $deviceInfo['device_type'] ?? 'unknown',
                'status' => 'failed',
            ]);

            return [
                'success' => false,
                'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة'
            ];
        }

        // التحقق من قفل الحساب
        if ($user->isLocked()) {
            //  تسجيل محاولة مقفولة
            LoginHistory::create([
                'user_id' => $user->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device_type' => $deviceInfo['device_type'] ?? 'unknown',
                'status' => 'locked',
            ]);

            return [
                'success' => false,
                'message' => 'الحساب مقفل. حاول بعد ' . $user->getLockRemainingMinutes() . ' دقيقة'
            ];
        }

        // التحقق من تفعيل الحساب (من قبل المدير)
        if (!$user->is_active) {
            return [
                'success' => false,
                'message' => 'الحساب غير مفعل. في انتظار موافقة الإدارة'
            ];
        }

        // التحقق من كلمة المرور
        if (!Hash::check($password, $user->password)) {
            $user->recordFailedAttempt();

            //  تسجيل محاولة فاشلة
            LoginHistory::create([
                'user_id' => $user->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device_type' => $deviceInfo['device_type'] ?? 'unknown',
                'status' => 'failed',
            ]);

            $remaining = $user->getRemainingAttempts();

            if ($user->isLocked()) {
                return [
                    'success' => false,
                    'message' => 'تم قفل الحساب لمدة 30 دقيقة'
                ];
            }

            return [
                'success' => false,
                'message' => 'كلمة المرور غير صحيحة. متبقي ' . $remaining . ' محاولات'
            ];
        }

        // إعادة تعيين المحاولات الفاشلة
        $user->resetFailedAttempts();

        //  REMEMBER ME + تحديد مدة التوكن
        $remember = $deviceInfo['remember'] ?? false;
        $expiration = $remember
            ? now()->addDays(30)  // شهر كامل
            : now()->addHours(2); // ساعتين فقط

        // إنشاء توكن
        $token = $user->createToken('auth_token', ['*'], $expiration)->plainTextToken;
        $tokenId = explode('|', $token)[0];

        //  تخزين remember token إذا طلب ذلك
        if ($remember) {
            $user->update([
                'remember_token' => Str::random(60),
                'remember_expires_at' => now()->addDays(30),
            ]);
        }

        // تسجيل الجهاز في القائمة النشطة
        $user->addActiveToken($tokenId, $deviceInfo);

        // تحديث معلومات الدخول
        $user->recordLogin($deviceInfo);

        //  تسجيل دخول ناجح في Login History
        LoginHistory::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'device_type' => $deviceInfo['device_type'] ?? 'unknown',
            'status' => 'success',
        ]);

        return [
            'success' => true,
            'message' => $remember ? 'تم تسجيل الدخول مع تذكرني' : 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => $this->formatUser($user),
                'token' => $token,
                'remember' => $remember,
                'expires_in' => $remember ? '30 يوم' : 'ساعتين'
            ]
        ];
    }

    
    //  تسجيل الخروج
    
    public function logout(User $user, bool $allDevices = false): array
    {
        if ($allDevices) {
            $user->tokens()->delete();
            $message = 'تم تسجيل الخروج من جميع الأجهزة';
        } else {
            $currentTokenId = request()->user()->currentAccessToken()->id;
            $user->revokeToken($currentTokenId);
            $message = 'تم تسجيل الخروج بنجاح';
        }

        return [
            'success' => true,
            'message' => $message
        ];
    }

    
    // تغيير كلمة المرور (مع فحص القوة + تنبيه أمان)
    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        //  فحص قوة كلمة المرور الجديدة
        $passwordService = new PasswordStrengthService();
        $strengthResult = $passwordService->validate($newPassword);

        if (!$strengthResult['is_valid']) {
            return [
                'success' => false,
                'message' => 'كلمة المرور ليست قوية بما يكفي',
                'errors' => $strengthResult['feedback']
            ];
        }

        //  فحص الحد الأدنى للطول
        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'
            ];
        }

        //  التحقق من كلمة المرور الحالية
        if (!Hash::check($currentPassword, $user->password)) {
            return [
                'success' => false,
                'message' => 'كلمة المرور الحالية غير صحيحة'
            ];
        }

        //  تحديث كلمة المرور
        $user->update([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now()
        ]);

        //  حذف جميع الجلسات الأخرى (يبقى الجهاز الحالي فقط)
        $user->tokens()->where('id', '!=', request()->user()->currentAccessToken()->id)->delete();

        //  إرسال تنبيه أمني بالإيميل
        try {
            $this->sendSecurityAlert($user);
        } catch (\Exception $e) {
            \Log::error('فشل إرسال تنبيه الأمان: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح. تم إرسال تنبيه أمني إلى بريدك.',
            'data' => [
                'password_strength' => $strengthResult
            ]
        ];
    }

    
    // طلب إعادة تعيين كلمة المرور
    public function requestPasswordReset(string $email): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => true,
                'message' => 'إذا كان البريد مسجلاً، سيصلك رابط إعادة التعيين'
            ];
        }

        PasswordReset::where('email', $email)->delete();

        $token = Str::random(64);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordReset::create([
            'email' => $email,
            'token' => $token,
            'code' => $code,
            'expires_at' => now()->addHours(2)
        ]);

        return [
            'success' => true,
            'message' => 'تم إرسال رابط إعادة التعيين إلى بريدك الإلكتروني',
            'data' => [
                'token' => $token,
                'code' => $code
            ]
        ];
    }

    
    //  تأكيد إعادة تعيين كلمة المرور
    
    public function confirmPasswordReset(string $token, string $code, string $newPassword): array
    {
        $reset = PasswordReset::where('token', $token)
            ->where('code', $code)
            ->where('used', false)
            ->first();

        if (!$reset) {
            return [
                'success' => false,
                'message' => 'رمز التحقق غير صحيح'
            ];
        }

        if ($reset->expires_at->isPast()) {
            return [
                'success' => false,
                'message' => 'انتهت صلاحية الرابط'
            ];
        }

        $user = User::where('email', $reset->email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'المستخدم غير موجود'
            ];
        }

        $user->update([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now()
        ]);

        $user->tokens()->delete();
        $reset->markAsUsed();

        return [
            'success' => true,
            'message' => 'تم إعادة تعيين كلمة المرور بنجاح'
        ];
    }

    
    // الحصول على الأجهزة النشطة
    
    public function getActiveDevices(User $user): array
    {
        $devices = [];
        $currentTokenId = request()->user()->currentAccessToken()->id;

        foreach ($user->tokens as $token) {
            $devices[] = [
                'id' => $token->id,
                'name' => $token->name,
                'is_current' => $token->id === $currentTokenId,
                'last_used' => $token->last_used_at?->diffForHumans(),
                'created' => $token->created_at->diffForHumans()
            ];
        }

        return [
            'success' => true,
            'data' => [
                'devices' => $devices,
                'total' => count($devices)
            ]
        ];
    }

    
    // إلغاء جهاز محدد
    public function revokeDevice(User $user, string $tokenId): array
    {
        if ($tokenId === request()->user()->currentAccessToken()->id) {
            return [
                'success' => false,
                'message' => 'لا يمكن إلغاء الجهاز الحالي'
            ];
        }

        $user->tokens()->where('id', $tokenId)->delete();

        return [
            'success' => true,
            'message' => 'تم إلغاء الجهاز بنجاح'
        ];
    }


    
    // تنسيق بيانات المستخدم (مع دعم المدير)
    private function formatUser(User $user): array
    {
        $data = [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'full_name' => $user->full_name,
            'email_verified' => $user->isVerified()
        ];

        //  المدير
        if ($user->isAdmin()) {
            $data['profile'] = [
                'type' => 'admin',
                'access_level' => 'full_access',
                'permissions' => ['manage_teachers', 'manage_students', 'manage_system']
            ];
            return $data;
        }

        // المعلم والطالب
        $profile = $user->profile();

        if ($profile) {
            if ($user->isTeacher()) {
                $data['profile'] = [
                    'teacher_id' => $profile->teacher_id,
                    'specialization' => $profile->specialization,
                    'employment_status' => $profile->employment_status
                ];
            }
            if ($user->isStudent()) {
                $data['profile'] = [
                    'student_id' => $profile->student_id,
                    'grade' => $profile->grade,
                    'section' => $profile->section,
                    'enrollment_status' => $profile->enrollment_status,
                    'wallet_balance' => $profile->wallet_balance
                ];
            }
        }

        return $data;
    }

    
    // إرسال تنبيه أمني عند تغيير كلمة المرور
     
    private function sendSecurityAlert(User $user): void
    {
        $message = "مرحباً {$user->full_name}!\n\n";
        $message .= "🔐 تنبيه أمني: تم تغيير كلمة المرور الخاصة بحسابك.\n\n";
        $message .= "📅 التاريخ والوقت: " . now()->format('Y-m-d H:i:s') . "\n";
        $message .= "🌐 عنوان IP: " . request()->ip() . "\n";
        $message .= "💻 المتصفح: " . request()->userAgent() . "\n\n";
        $message .= "إذا لم تكن أنت من قام بهذا التغيير:\n";
        $message .= "1. سجل الدخول فوراً وغير كلمة المرور\n";
        $message .= "2. تواصل مع الدعم الفني فوراً\n";
        $message .= "3. راجع نشاط حسابك\n\n";
        $message .= "للتواصل مع الدعم: support@school.com\n\n";
        $message .= "مع تحيات إدارة المدرسة";

        Mail::raw($message, function ($mail) use ($user) {
            $mail->to($user->email)
                ->subject('🔐 تنبيه أمني: تم تغيير كلمة المرور');
        });
    }
}