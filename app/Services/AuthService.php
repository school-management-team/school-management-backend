<?php

namespace App\Services;

use App\Models\User;
use App\Models\Student;
use App\Models\Guardian;
use App\Models\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;


class AuthService
{
    // تسجيل الدخول
    public function login(string $login, string $password, bool $remember = false): array
    {
        // تحديد إذا كان بريد أو رقم هاتف
        $user = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $login)->first()
            : User::where('phone', $login)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'البريد الإلكتروني أو رقم الهاتف او كلمة المرور غير صحيحة'
            ];
        }


        // التحقق من قفل الحساب
        if ($user->isLocked()) {

            return [
                'success' => false,
                'message' => 'الحساب مقفل. حاول بعد ' . $user->getLockRemainingMinutes() . ' دقيقة'
            ];
        }

        // التحقق من تفعيل الحساب (من قبل المدير)
        if (!$user->is_active) {
            return [
                'success' => false,
                'message' => 'الحساب غير مقبول حتى الان. في انتظار موافقة الإدارة'
            ];
        }
        if(!$user->isVerified()){
            return [
                'success' => false,
                'message' => 'لم تقم بتفعيل حسابك بعد'
            ];
        }
        // التحقق من كلمة المرور
        if (!Hash::check($password, $user->password)) {
            $user->recordFailedAttempt();
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
        // مدة التوكن حسب تذكرني
        $expiration = $remember ? now()->addDays(30) : now()->addHours(2);

        // إنشاء توكن
        $token = $user->createToken('auth_token', ['*'], $expiration)->plainTextToken;

        // تحديث آخر دخول
        $user->update(['last_login_at' => now()]);

        return [
            'success' => true,
            'message' => $remember ? 'تم تسجيل الدخول مع تذكرني' : 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => $this->formatUser($user),
                'token' => $token,
                'remember' => $remember,
                'expires_at' => $expiration->toDateTimeString()
            ]
        ];
    }


    //  تسجيل الخروج

    public function logout(User $user): array
    {
        $user->currentAccessToken()->delete();
        return [
            'success' => true,
            'message' => 'تم تسجيل الخروج بنجاح'
        ];
    }


    // تغيير كلمة المرور (مع فحص القوة + تنبيه أمان)
    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {

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

        return [
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح.',

        ];
    }

    //  تأكيد إعادة تعيين كلمة المرور

    public function confirmPasswordReset(string $login, string $code, string $newPassword): array
    {
        //  البحث بالبريد أو الرقم
        $user = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $login)->first()
            : User::where('phone', $login)->first();

        if (!$user) {
            return [
            'success' => false,
            'message' => 'المستخدم غير موجود'
            ];
        }


        if (!$user->verifyCode($code)) {
            return [
                'success' => false,
                'message' => 'الرمز غير صحيح أو منتهي الصلاحية'
            ];
        }

        $user->update([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now()
        ]);

        $user->tokens()->delete();

        return [
            'success' => true,
            'message' => 'تم إعادة تعيين كلمة المرور بنجاح'
        ];
    }



    // تنسيق بيانات المستخدم (مع دعم المدير)
     function formatUser(User $user): array
    {
        $data = [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
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
                    'teacher_id' => $profile->id,
                    'teacher_name' => $profile->teacher_name,
                    'grade' =>$profile->grade,
                    'specialization' => $profile->specialization,
                    'status' => $profile->status
                ];
            }
            if ($user->isStudent()) {
                $data['profile'] = [
                    'student_id' => $profile->id,
                    'student_name' => $profile->student_name,
                    'grade' => $profile->grade,
                    'education_level' => $profile->education_level,
                    'status' => $profile->status

                ];
            }
        }

        if ($user->isGuardian() && $user->guardian) {
            $data['profile'] = [
            'guardian_name' => $user->guardian->guardian_name,
            'relationship' => $user->guardian->relationship,
            'children_count' => $user->guardian->number_of_children
            ];
        }

        return $data;
    }
    public function findStudentByNumber(string $studentNumber): ?Student
    {
        return Student::where('student_number', $studentNumber)
            ->where('status', 'active')
            ->first();
    }

    /**
     * التحقق من أن الطالب غير مرتبط بالفعل بهذا ولي الأمر
     */
    public function isStudentLinkedToGuardian(Student $student, Guardian $guardian): bool
    {
        return $guardian->students()
            ->where('student_id', $student->id)
            ->exists();
    }

    /**
     * تحديث معلومات الطالب من ولي الأمر
     */
    public function updateStudentParentInfo(Student $student, Guardian $guardian, string $relationship): void
    {
        if ($relationship === 'father' && !$student->father_name) {
            $student->update(['father_name' => $guardian->guardian_name]);
        } elseif ($relationship === 'mother' && !$student->mother_name) {
            $student->update(['mother_name' => $guardian->guardian_name]);
        }
    }

    /**
     * ربط الطالب بولي الأمر
     */
    public function linkStudentToGuardian(Student $student, Guardian $guardian, string $relationship, bool $isPrimary = false): array
    {
        // التحقق من عدم الربط مسبقاً
        if ($this->isStudentLinkedToGuardian($student, $guardian)) {
            return [
                'success' => false,
                'message' => 'هذا الطالب مرتبط بك بالفعل'
            ];
        }

        // تحديث معلومات الطالب
        $this->updateStudentParentInfo($student, $guardian, $relationship);

        // تحديد إذا كان أول طالب (أساسي)
        $isPrimary = $isPrimary || $guardian->students()->count() === 0;

        // ربط الطالب بولي الأمر
        $guardian->students()->attach($student->id, [
            'relationship' => $relationship,
            'is_primary' => $isPrimary
        ]);

        return [
            'success' => true,
            'message' => 'تم ربط الطالب بولي الأمر بنجاح',
            'data' => [
                'student_id' => $student->id,
                'student_name' => $student->student_name,
                'student_number' => $student->student_number,
                'relationship' => $relationship,
                'is_primary' => $isPrimary
            ]
        ];
    }
}
