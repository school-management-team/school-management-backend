<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Supervisor;
use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Guardian;
use App\Services\AuthService;
use App\Services\GuardianVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'email' => 'required|email',
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
            $request->email,
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
    // أضف هذا السطر بأول كل تابع تسجيل، قبل استدعاء Validator::make
$request->merge([
    'gender' => match ($request->gender) {
        'ذكر' => 'male',
        'أنثى' => 'female',
        default => $request->gender, // لو أصلاً أرسل male/female مباشرة، يبقى كما هو
    },
]);

    $validator = Validator::make($request->all(), [
        'email' => 'required|string|unique:users,email',
        'password' => 'required|string|min:8|confirmed',
        'phone' =>['required', 'regex:/^09[0-9]{8}$/', 'unique:users,phone'],
        'user_name' => 'required|string|max:100',
        'gender' => 'required|in:male,female',
        'birth_date' => ['required', 'date', 'after_or_equal:' . now()->subYears(15)->format('Y-m-d'),'before_or_equal:' . now()->subYears(5)->format('Y-m-d')],
        'father_name' => 'required|string|max:100',
        'mother_name' => 'required|string|max:100',
        'class_id' => 'required|integer|exists:classes,id',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }
        $class = SchoolClass::find($request->class_id);
    if (!$class) {
        return response()->json([
            'success' => false,
            'message' => 'الصف المحدد غير موجود'
        ], 422);
    }

    // التحقق من أن الصف له نطاق أرقام في الـ config
    $ranges = config('school.student_number_ranges');
    if (!isset($ranges[$class->grade_order])) {
        return response()->json([
            'success' => false,
            'message' => "الصف {$class->name} غير معرف في نظام الترقيم، يرجى التواصل مع الإدارة"
        ], 422);
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
        $this->sendEmailCode($user, $code);
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح , يرجى تفعيل الحساب.',
            'data' => ['user_id' => $user->id, 'requires_verification' => true],
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
    // أضف هذا السطر بأول كل تابع تسجيل، قبل استدعاء Validator::make
$request->merge([
    'gender' => match ($request->gender) {
        'ذكر' => 'male',
        'أنثى' => 'female',
        default => $request->gender, // لو أصلاً أرسل male/female مباشرة، يبقى كما هو
    },
]);
    $validator = Validator::make($request->all(), [
        'email' => 'required|string|unique:users,email',
        'password' => 'required|string|min:8|confirmed',
        'phone' => ['required', 'regex:/^09[0-9]{8}$/', 'unique:users,phone'],
        'user_name' => 'required|string|max:100',
        'gender' => 'required|in:male,female',
        'birth_date' => ['required', 'date', 'before_or_equal:' . now()->subYears(21)->format('Y-m-d')],
        'subject_id' => 'required|exists:subjects,id',
        'stage_id' => 'required|exists:stages,id',
        'cv' => 'required|string|max:5000',
        'legal_document_path' => 'required|file|mimes:pdf,jpg,jpeg,png|max:4096',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }
    $stage = Stage::find($request->stage_id);
    if (!$stage->subjects()->where('subjects.id', $request->subject_id)->exists()) {
        return response()->json([
        'success' => false,
        'message' => 'هذه المادة غير متاحة لهذه المرحلة',
        ], 422);
    }

    DB::beginTransaction();
    try {
        $user = $this->authService->createUser($request->all(), 'teacher');

        $documentPath = $request->file('legal_document_path')->store('legal_documents', 'public');

        Teacher::create([
            'user_id' => $user->id,
            'subject_id' => $request->subject_id,
            'stage_id' => $request->stage_id,
            'cv' => $request->cv,
            'legal_document_path' => $documentPath,
        ]);

        $code = $user->generateVerificationCode();
        $this->sendEmailCode($user, $code);
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح , يرجى تفعيل الحساب.',
            'data' => ['user_id' => $user->id, 'requires_verification' => true],
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
    // أضف هذا السطر بأول كل تابع تسجيل، قبل استدعاء Validator::make
$request->merge([
    'gender' => match ($request->gender) {
        'ذكر' => 'male',
        'أنثى' => 'female',
        default => $request->gender, // لو أصلاً أرسل male/female مباشرة، يبقى كما هو
    },
]);
    $validator = Validator::make($request->all(), [

        'email' => 'required|email|unique:users,email',

        'password' => 'required|string|min:8|confirmed',

        'phone' =>  ['required', 'regex:/^09[0-9]{8}$/', 'unique:users,phone'],

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

        $this->sendEmailCode($user, $code);

        DB::commit();

        return response()->json([

            'success' => true,

            'message' => 'تم إنشاء الحساب بنجاح , يرجى تفعيل الحساب. ',

            'data' => [

                'user_id' => $user->id,

                'requires_verification' => true,
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
    // أضف هذا السطر بأول كل تابع تسجيل، قبل استدعاء Validator::make
$request->merge([
    'gender' => match ($request->gender) {
        'ذكر' => 'male',
        'أنثى' => 'female',
        default => $request->gender, // لو أصلاً أرسل male/female مباشرة، يبقى كما هو
    },
]);
    $validator = Validator::make($request->all(), [

        'email' => 'required|email|unique:users,email',

        'password' => 'required|string|min:8|confirmed',

        'phone' => ['required', 'regex:/^09[0-9]{8}$/', 'unique:users,phone'],

        'user_name' => 'required|string|max:100',

        'gender' => 'required|in:male,female',

        'birth_date' => ['required', 'date', 'before_or_equal:' . now()->subYears(25)->format('Y-m-d')],

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

        $this->sendEmailCode($user, $code);

        DB::commit();

        return response()->json([

            'success' => true,

            'message' => 'تم إنشاء الحساب بنجاح , يرجى تفعيل الحساب .',

            'data' => [

                'user_id' => $user->id,

                'requires_verification' => true,

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
        'email' => 'required|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $user = User::where('email', $request->email)->first();
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'الحساب غير موجود'
        ], 404);
    }

    $code = $user->generateVerificationCode();

    $this->sendEmailCode($user, $code);

    return response()->json([
        'success' => true,
        'message' => 'تم إرسال رمز إعادة تعيين كلمة المرور.',
    ]);
}

// Reset Password
public function resetPassword(Request $request)
{
    $validator = Validator::make($request->all(), [

        'email' => 'required|string',

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

        $request->email,

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
        'email' => 'required|string',
        'code' => 'required|string|size:6'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $user = User::where('email', $request->email)->first();

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
        'email' => 'required|email'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $user = User::where('email', $request->email)->first();
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ], 404);
    }
    if ($user->status !== 'unverified') {
        return response()->json([
            'success' => false,
            'message' => 'لا يمكن ارسال الرمز لهذا الحساب، الحساب مفعّل مسبقاً',
        ], 400);
    }

    $code = $user->generateVerificationCode();

    $this->sendEmailCode($user, $code);


    return response()->json([
        'success' => true,
        'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني.',
    ]);
}
public function profile(Request $request)
{
    return response()->json([
        'success' => true,
        'data' => $this->authService->formatUser($request->user())
    ]);
}

// رفع/تحديث صورة البروفايل (لكل الأدوار)
public function updateProfilePhoto(Request $request)
{
    $validator = Validator::make($request->all(), [
        'photo' => 'required|image|max:2048',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $user = $request->user();

    if ($user->profile_photo_path) {
        Storage::disk('public')->delete($user->profile_photo_path);
    }

    $path = $request->file('photo')->store('profile-photos', 'public');

    $user->update(['profile_photo_path' => $path]);

    return response()->json([
        'success' => true,
        'message' => 'تم تحديث الصورة الشخصية بنجاح',
        'data' => ['profile_photo_path' => $path],
    ]);
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



public function stages()
{

    return response()->json([
        'success' => true,
        'data' => Stage::orderBy('id')->get(['id', 'name']),
    ]);
}

public function subjects(int $stageId)
{
    $stage = Stage::with('subjects:id,name')->find($stageId);

    if (!$stage) {
        return response()->json(['success' => false, 'message' => 'المرحلة غير موجودة'], 404);
    }

    return response()->json(['success' => true, 'data' => $stage->subjects]);
}

public function classesByStage(int $stageId)
{
    $classes = SchoolClass::where('stage_id', $stageId)
        ->orderBy('grade_order')
        ->get(['id', 'name', 'grade_order']);

    return response()->json(['success' => true, 'data' => $classes]);
}


}
