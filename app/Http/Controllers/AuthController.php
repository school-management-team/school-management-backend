<?php

namespace App\Http\Controllers;

use App\Models\Supervisor;
use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Guardian;
use App\Services\AuthService;
use App\Services\GuardianVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    protected AuthService $authService ;
    protected GuardianVerificationService $guardianVerificationService;

    public function __construct(AuthService $authService,GuardianVerificationService $guardianVerificationService)
    {
        $this->authService = $authService;
        $this->guardianVerificationService=$guardianVerificationService;
    }

    //Login
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string',
            'password' => 'required|string',
            'remember' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $this->authService->login(
            $request->login,
            $request->password,
            $request->remember ?? false
        );

        return response()->json(
            $result,
            $result['success'] ? 200 : 401
        );
    }


    // Register Student

public function registerStudent(Request $request)
{
    $validator = Validator::make($request->all(), [
        'login' => 'required|string|unique:users,email|unique:users,phone',
        'password' => 'required|string|min:8|confirmed',
        'user_name' => 'required|string|max:100',
        'gender' => 'required|in:male,female',
        'birth_date' => 'required|date',
        'father_name' => 'required|string|max:100',
        'mother_name' => 'required|string|max:100',
        'class_id' => 'required|integer|exists:classes,id',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();
    try {
        $user = $this->authService->createUser($request->all(), 'student');

        Student::create([
            'user_id' => $user->id,
            'father_name' => $request->father_name,
            'mother_name' => $request->mother_name,
            'class_id' => $request->class_id,
            'enrollment_date' => now(),
        ]);

        $code = $user->generateVerificationCode();
        $link = $this->sendVerificationCode($user, $code);
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح بانتظار موافقة الادارة.',
            'data' => ['user_id' => $user->id, 'requires_verification' => true, 'whatsapp_link' => $link],
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء التسجيل',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

// Register Teacher


public function registerTeacher(Request $request)
{
    $validator = Validator::make($request->all(), [
        'login' => 'required|string|unique:users,email|unique:users,phone',
        'password' => 'required|string|min:8|confirmed',
        'user_name' => 'required|string|max:100',
        'gender' => 'required|in:male,female',
        'birth_date' => 'required|date',
        'specialization' => 'required|string|max:100',
        'cv' => 'required|string|max:5000',
        'legal_document_path' => 'required|file|mimes:pdf,jpg,jpeg,png|max:4096',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    DB::beginTransaction();
    try {
        $user = $this->authService->createUser($request->all(), 'teacher');

        $documentPath = $request->file('legal_document_path')->store('legal_documents', 'public');

        Teacher::create([
            'user_id' => $user->id,
            'specialization' => $request->specialization,
            'cv' => $request->cv,
            'legal_document_path' => $documentPath,
        ]);

        $code = $user->generateVerificationCode();
        $link = $this->sendVerificationCode($user, $code);
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح بانتظار موافقة الادارة.',
            'data' => ['user_id' => $user->id, 'requires_verification' => true, 'whatsapp_link' => $link],
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء التسجيل',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

public function registerGuardian(Request $request)
{
    $validator = Validator::make($request->all(), [

        'login' => 'required|string|unique:users,email|unique:users,phone',

        'password' => 'required|string|min:8|confirmed',

        'user_name' => 'required|string|max:100',

        'gender' => 'required|in:male,female',

        'birth_date' => 'required|date',

        'relationship' => 'required|in:father,mother',

        'verification_student_number' => 'required|string',

        'number_of_children' => 'required|integer|min:0|max:20'

    ]);

    if ($validator->fails()) {

        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);

    }

    DB::beginTransaction();

    try {

        // إنشاء المستخدم
        $user = $this->authService->createUser(
            $request->all(),
            'guardian'
        );

        // إنشاء بيانات ولي الأمر
        Guardian::create([

            'user_id' => $user->id,

            'relationship' => $request->relationship,

            'verification_student_number' => $request->verification_student_number,

            'number_of_children' =>$request->number_of_children

        ]);

        // إرسال كود التحقق
        $code = $user->generateVerificationCode();

        $link = $this->sendVerificationCode($user, $code);

        DB::commit();

        return response()->json([

            'success' => true,

            'message' => 'تم إنشاء الحساب بنجاح بانتظار موافقة الادارة. ',

            'data' => [

                'user_id' => $user->id,

                'requires_verification' => true,

                'whatsapp_link' => $link

            ]

        ], 201);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([

            'success' => false,

            'message' => 'حدث خطأ أثناء التسجيل',

            'error' => config('app.debug') ? $e->getMessage() : null

        ], 500);

    }
}

public function registerSupervisor(Request $request)
{
    $validator = Validator::make($request->all(), [

        'login' => 'required|string|unique:users,email|unique:users,phone',

        'password' => 'required|string|min:8|confirmed',

        'user_name' => 'required|string|max:100',

        'gender' => 'required|in:male,female',

        'birth_date' => 'required|date',

        'educational_qualification' => 'required|in:bachelor,master,doctorate',

        'specialization' => 'required|string|max:100',

        'bio' => 'required|string|max:5000',

        'cv_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:4096',

    ]);

    if ($validator->fails()) {

        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);

    }

    DB::beginTransaction();

    try {

        // إنشاء المستخدم
        $user = $this->authService->createUser(
            $request->all(),
            'supervisor'
        );

        // رفع الملف
        $cvPath = $request
            ->file('cv_file')
            ->store('supervisors/cv', 'local');

        // إنشاء بيانات المشرف
        Supervisor::create([

            'user_id' => $user->id,

            'educational_qualification' => $request->educational_qualification,

            'specialization' => $request->specialization,

            'bio' => $request->bio,

            'cv_file' => $cvPath,

        ]);

        // إنشاء وإرسال كود التحقق
        $code = $user->generateVerificationCode();

        $link = $this->sendVerificationCode($user, $code);

        DB::commit();

        return response()->json([

            'success' => true,

            'message' => 'تم إنشاء الحساب بنجاح بانتظار موافقة الادارة.',

            'data' => [

                'user_id' => $user->id,

                'requires_verification' => true,

                'whatsapp_link' => $link

            ]

        ], 201);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([

            'success' => false,

            'message' => 'حدث خطأ أثناء التسجيل',

            'error' => config('app.debug') ? $e->getMessage() : null

        ], 500);

    }
}

// Forgot Password
public function forgotPassword(Request $request)
{
    $validator = Validator::make($request->all(), [
        'login' => 'required|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $user = filter_var($request->login, FILTER_VALIDATE_EMAIL)
        ? User::where('email', $request->login)->first()
        : User::where('phone', $request->login)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'الحساب غير موجود'
        ], 404);
    }

    $code = $user->generateVerificationCode();

    $link = $this->sendVerificationCode($user, $code);

    return response()->json([
        'success' => true,
        'message' => 'تم إرسال رمز إعادة تعيين كلمة المرور.',
        'data' => [
            'whatsapp_link' => $link
        ]
    ]);
}

// Reset Password
public function resetPassword(Request $request)
{
    $validator = Validator::make($request->all(), [

        'login' => 'required|string',

        'code' => 'required|string|size:6',

        'password' => 'required|string|min:8|confirmed'

    ]);

    if ($validator->fails()) {

        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);

    }

    $result = $this->authService->confirmPasswordReset(

        $request->login,

        $request->code,

        $request->password

    );

    return response()->json(

        $result,

        $result['success'] ? 200 : 400

    );
}
public function logout(Request $request)
{
    $result = $this->authService->logout($request->user());

    return response()->json($result);
}
public function changePassword(Request $request)
{
    $validator = Validator::make($request->all(), [
        'current_password' => 'required|string',
        'new_password' => 'required|string|min:8|confirmed',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors(),
        ], 422);
    }

    $result = $this->authService->changePassword(
        $request->user(),
        $request->current_password,
        $request->new_password
    );

    return response()->json(
        $result,
        $result['success'] ? 200 : 400
    );
}

public function verifyAccount(Request $request)
{
    $validator = Validator::make($request->all(), [
        'login' => 'required|string',
        'code' => 'required|string|size:6'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $user = filter_var($request->login, FILTER_VALIDATE_EMAIL)
        ? User::where('email', $request->login)->first()
        : User::where('phone', $request->login)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ], 404);
    }

    if (!$user->verifyCode($request->code)) {
        return response()->json([
            'success' => false,
            'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية'
        ], 400);
    }
    if($user->isGuardian()){
        $studentNumber=$user->guardian->verification_student_number;
        $result=$this->guardianVerificationService->verifyMatch($user->guardian,$studentNumber);

        if(!$result['success']){
            return response()->json($result,400);
        }else{
            $user->update(['status'=>'pending']);
        }

    }else{
        $user->update(['status'=>'pending']);
    }


    return response()->json([
        'success' => true,
        'message' => 'تم تفعيل الحساب بنجاح، بانتظار موافقة الإدارة.'
    ]);
}
public function resendVerificationCode(Request $request)
{
    $validator = Validator::make($request->all(), [
        'login' => 'required|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $user = filter_var($request->login, FILTER_VALIDATE_EMAIL)
        ? User::where('email', $request->login)->first()
        : User::where('phone', $request->login)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ], 404);
    }
    if($user->status !=='unverified') {

    return response()->json([
        'success'=>false,
        'message' => 'لا يمكن ارسال الرمز لهذا الحساب']);
    }

    if ($user->isVerified()) {
        return response()->json([
            'success' => false,
            'message' => 'الحساب مفعل مسبقاً'
        ], 400);
    }

    $code = $user->generateVerificationCode();

    $whatsappLink = $this->sendVerificationCode($user, $code);

    $response = [];

    if ($whatsappLink) {
        $response['whatsapp_link'] = $whatsappLink;
        $message = 'تم إنشاء رابط واتساب لإرسال رمز التحقق.';
    } else {
        $message = 'تم إرسال رمز التحقق إلى بريدك الإلكتروني.';
    }

    return response()->json([
        'success' => true,
        'message' => $message,
        'data' => $response
    ]);
}
public function profile(Request $request)
{
    $user = $request->user();

    $user->load([
        'teacher',
        'student.guardians',
        'guardian.students',
        'supervisor'
    ]);

    return response()->json([
        'success' => true,
        'data' => $this->authService->formatUser($user)
    ]);
}
private function sendVerificationCode(User $user, string $code): ?string
{
    if ($user->email) {

        $this->sendEmailCode($user, $code);

        return null;
    }

    if ($user->phone) {

        return $this->sendSmsCode($user, $code);

    }

    return null;
}
private function sendEmailCode(User $user, string $code): void
{
    $message =

"مرحباً {$user->user_name}

كود التحقق الخاص بك هو:

{$code}

صلاحية الكود 30 دقيقة.

نظام إدارة المدرسة.";

    Mail::raw($message, function ($mail) use ($user) {

        $mail->to($user->email)
             ->subject('رمز التحقق');

    });
}
private function sendSmsCode(User $user, string $code): string
{
    return "https://wa.me/{$user->phone}?text=" .
        urlencode("رمز التحقق الخاص بك هو: {$code}");
}
}
