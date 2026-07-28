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
    $user = filter_var($login, FILTER_VALIDATE_EMAIL)
        ? User::where('email', $login)->first()
        : User::where('phone', $login)->first();

    if (!$user) {
        return [
            'success' => false,
            'message' => 'بيانات تسجيل الدخول غير صحيحة'
        ];
    }

    if ($user->isLocked()) {
        return [
            'success' => false,
            'message' => 'الحساب مقفل لمدة '.$user->getLockRemainingMinutes().' دقيقة'
        ];
    }

    if ($user->status!=='active') {
        return [
            'success' => false,
            'message' => 'لا يمكن تسجيل الدخول الان يرجى الانتظار'
        ];
    }



    if (!Hash::check($password, $user->password)) {

        $user->recordFailedAttempt();

        return [
            'success'=>false,
            'message'=>'كلمة المرور غير صحيحة'
        ];
    }

    $user->resetFailedAttempts();

    $expiration = $remember
        ? now()->addDays(30)
        : now()->addHours(2);

    $token = $user
        ->createToken('auth_token',['*'],$expiration)
        ->plainTextToken;

    $user->update([
        'last_login_at'=>now()
    ]);

    return [
        'success'=>true,
        'message'=>'تم تسجيل الدخول بنجاح',
        'data'=>[
            'user'=>$this->formatUser($user),
            'token'=>$token,
            'expires_at'=>$expiration
        ]
    ];
}
public function createUser(array $data, string $role): User
{
    $email = filter_var($data['login'], FILTER_VALIDATE_EMAIL)
        ? $data['login']
        : null;

    $phone = filter_var($data['login'], FILTER_VALIDATE_EMAIL)
        ? null
        : $data['login'];

    return User::create([
        'user_name' => $data['user_name'],
        'email' => $email,
        'phone' => $phone,
        'password' => Hash::make($data['password']),
        'role' => $role,
        'gender' => $data['gender'],
        'birth_date' => $data['birth_date'],
        'status' => 'unverified',
        'password_changed_at' => now(),
    ]);
}
    //  تسجيل الخروج

public function logout(User $user): array
{
    $user->currentAccessToken()->delete();

    return [
        'success'=>true,
        'message'=>'تم تسجيل الخروج'
    ];
}


    // تغيير كلمة المرور
public function changePassword(User $user,string $current,string $new): array
{
    if(!Hash::check($current,$user->password))
    {
        return [
            'success'=>false,
            'message'=>'كلمة المرور الحالية غير صحيحة'
        ];
    }

    $user->update([
        'password'=>Hash::make($new),
        'password_changed_at'=>now()
    ]);

    return [
        'success'=>true,
        'message'=>'تم تغيير كلمة المرور'
    ];
}
    //  تأكيد إعادة تعيين كلمة المرور

public function confirmPasswordReset(
    string $login,
    string $code,
    string $password
): array
{
    $user = filter_var($login,FILTER_VALIDATE_EMAIL)
        ? User::where('email',$login)->first()
        : User::where('phone',$login)->first();

    if(!$user)
    {
        return [
            'success'=>false,
            'message'=>'المستخدم غير موجود'
        ];
    }

    if(!$user->verifyCode($code))
    {
        return [
            'success'=>false,
            'message'=>'رمز التحقق غير صحيح أو منتهي الصلاحية'
        ];
    }

    $user->update([
        'password'=>Hash::make($password),
        'password_changed_at'=>now()
    ]);

    $user->tokens()->delete();

    return [
        'success'=>true,
        'message'=>'تم تغيير كلمة المرور'
    ];
}
public function formatUser(User $user): array
{
    $user->load(['student.schoolClass.stage', 'student.section', 'teacher', 'guardian.students', 'supervisor']);

    $data = [
        'id' => $user->id,
        'user_name' => $user->user_name,
        'email' => $user->email,
        'phone' => $user->phone,
        'role' => $user->role,
        'status' => $user->status,
        'gender' => $user->gender,
        'birth_date' => $user->birth_date,
        'email_verified' => $user->isVerified(),
    ];

    if ($user->isAdmin()) return $data;

    if ($user->isStudent()) {
        $data['profile'] = [
            'student_number' => $user->student?->student_number,
            'father_name' => $user->student?->father_name,
            'mother_name' => $user->student?->mother_name,
            'class' => $user->student?->schoolClass?->name,
            'stage' => $user->student?->schoolClass?->stage?->name,
            'section' => $user->student?->section?->name,
            'enrollment_date' => $user->student?->enrollment_date,
        ];
    }

    if ($user->isTeacher()) {
        $data['profile'] = [
            'specialization' => $user->teacher?->specialization,
            'cv' => $user->teacher?->cv,
        ];
    }

    if($user->isGuardian())
    {
        $data['profile']=[

            'relationship'=>$user->guardian?->relationship,

            'students'=>$user->guardian
                ?->students
                ->map(function($student){

                    return [

                        'id'=>$student->id,

                        'student_number'=>$student->student_number,

                        'father_name'=>$student->father_name,

                        'mother_name'=>$student->mother_name,

                        'school_class'=>$student->class_id

                    ];

                })
        ];
    }
    if($user->isSupervisor())
    {
        $data['profile']=[
            'educational_qualification'=>$user->supervisor?->educational_qualification,
            'specialization'=>$user->supervisor?->specialization,
            'bio'=>$user->supervisor?->bio
        ];
    }

    return $data;
}
public function findStudentByNumber(string $studentNumber): ?Student
{
    return Student::where('student_number',$studentNumber)
        ->first();
}
public function isStudentLinkedToGuardian(
    Student $student,
    Guardian $guardian
): bool
{
    return $guardian
        ->students()
        ->where('student_id',$student->id)
        ->exists();
}

public function linkStudentToGuardian(
    Student $student,
    Guardian $guardian
): array
{
    if($this->isStudentLinkedToGuardian($student,$guardian))
    {
        return [

            'success'=>false,
            'message'=>'الطالب مرتبط مسبقاً'
        ];
    }

    $guardian
        ->students()
        ->attach($student->id);

    return [

        'success'=>true,
        'message'=>'تم ربط الطالب بولي الأمر بنجاح'
    ];
}
}
