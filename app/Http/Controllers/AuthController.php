<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Guardian;
use App\Services\AuthService;
use App\Services\PasswordStrengthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }


    // تسجيل الدخول
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


        $result = $this->authService->login($request->login,
            $request->password,
            $request->remember ?? false);

        $statusCode = $result['success'] ? 200 : 401;
        return response()->json($result, $statusCode);
    }


    // تسجيل طالب جديد

    public function registerStudent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string|unique:users,email|unique:users,phone',
            'password' => 'required|string|min:8|confirmed',
            'student_name' => 'required|string|max:50',
            'father_name' => 'required|string|max:50',
            'mother_name' => 'required|string|max:50',
            'gender' => 'required|in:male,female',
            'birth_date' => 'required|date',
            'education_level' => 'required|in:primary,middle,high',
            'grade' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
                // تحديد إذا كان بريد أو رقم
                if (filter_var($request->login, FILTER_VALIDATE_EMAIL)) {
                    $email = $request->login;
                    $phone = null;
                } else {
                    $email = null;
                    $phone = $request->login;
                }
            $student = Student::create([
                'student_name' => $request->student_name,
                'father_name' => $request->father_name,
                'mother_name' => $request->mother_name,
                'gender' => $request->gender,
                'birth_date' => $request->birth_date,
                'education_level' => $request->education_level,
                'grade' => $request->grade,
                'status' => 'unverified',
                'enrollment_date' => now(),
            ]);

            if (!$student || !$student->id) {
                throw new \Exception('فشل في إنشاء سجل الطالب');
            }
            if ($request->has('guardian_id') && $request->guardian_id) {
            $student->guardians()->attach($request->guardian_id, [
                'relationship' => $request->guardian_relationship,
                'is_primary' => true
            ]);
        }

            $user = User::create([
               'email' => $email,
                'phone' => $phone,
                'password' => Hash::make($request->password),
                'role' => 'student',
                'student_id' => $student->id,
                'is_active' => false,
                'password_changed_at' => now()
            ]);

            if (!$user || !$user->id) {
                throw new \Exception('فشل في إنشاء سجل المستخدم');
            }

            //  إرسال كود تفعيل البريد
            $code = $user->generateVerificationCode();
            $whatsappLink= $this->sendVerificationCode($user, $code);

            $responseData = [
                'user_id' => $user->id,
                'requires_verification' => true
            ];

            if ($whatsappLink) {
                $responseData['whatsapp_link'] = $whatsappLink;
                $message = 'تم التسجيل بنجاح. جاري توجيهك إلى واتساب للتفعيل...';
            } else {
                $message = 'تم التسجيل بنجاح. يرجى تفعيل بريدك الإلكتروني.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $responseData
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء التسجيل'
            ], 500);
        }
    }

    // تسجيل معلم جديد

    public function registerTeacher(Request $request)
    {
        $validator = Validator::make($request->all(), [
           'login' => 'required|string|unique:users,email|unique:users,phone',
           'password' => 'required|string|min:8|confirmed',
            'teacher_name' => 'required|string|max:50',
            'gender' => 'required|in:male,female',
            'birth_date' => 'required|date',
            'education_level' => 'required|in:primary,middle,high',
            'grade' => 'required|string',
            'specialization' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            //  تحديد إذا كان بريد أو رقم
            if (filter_var($request->login, FILTER_VALIDATE_EMAIL)) {
                $email = $request->login;
                $phone = null;
            } else {
                $email = null;
                $phone = $request->login;
            }

            $teacher = Teacher::create([
                'teacher_name' => $request->teacher_name,
                'gender' => $request->gender,
                'birth_date' => $request->birth_date,
                'education_level' => $request->education_level,
                'grade' => $request->grade,
                'specialization' => $request->specialization,
                'status' => 'unverified',
            ]);

            $user = User::create([
                'email' => $email,
                'phone' => $phone,
                'password' => Hash::make($request->password),
                'role' => 'teacher',
                'teacher_id' => $teacher->id,
                'is_active' => false,
                'password_changed_at' => now()
            ]);

            if (!$user || !$user->id) {
                throw new \Exception('فشل في إنشاء سجل المستخدم');
            }

            //  إرسال كود تفعيل البريد
            $code = $user->generateVerificationCode();
            $whatsappLink=$this->sendVerificationCode($user, $code);

            $responseData = [
                'user_id' => $user->id,
                'requires_verification' => true
            ];

            if ($whatsappLink) {

                $responseData['whatsapp_link'] = $whatsappLink;
                $message = 'تم التسجيل بنجاح. جاري توجيهك إلى واتساب للتفعيل...';
            } else {
                $message = 'تم التسجيل بنجاح. يرجى تفعيل بريدك الإلكتروني.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $responseData
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء التسجيل'
            ], 500);
        }
    }

    // تسجيل ولي أمر جديد
    public function registerGuardian(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string|unique:users,email|unique:users,phone',
            'guardian_name' => 'required|string|max:50',
            'relationship' => 'required|in:father,mother',
            'number_of_children' => 'required|integer',
            'password' => 'required|string|min:8|confirmed',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            if (filter_var($request->login, FILTER_VALIDATE_EMAIL)) {
                $email = $request->login;
                $phone = null;
            } else {
                $email = null;
                $phone = $request->login;
            }

            $guardian = Guardian::create([
            'guardian_name' => $request->guardian_name,
            'relationship' => $request->relationship,
            'number_of_children'=>$request->number_of_children
            ]);

            $user = User::create([
                'email' => $email,
                'phone' => $phone,
                'password' => Hash::make($request->password),
                'role' => 'guardian',
                'guardian_id' => $guardian->id,
                'is_active' => true,
                'password_changed_at' => now()
            ]);

            $code = $user->generateVerificationCode();
            $whatsappLink=$this->sendVerificationCode($user, $code);

            $responseData = [
                'user_id' => $user->id,
                'requires_verification' => true
            ];

            if ($whatsappLink) {
                $responseData['whatsapp_link'] = $whatsappLink;
                $message = 'تم التسجيل بنجاح. جاري توجيهك إلى واتساب للتفعيل...';
            } else {
                $message = 'تم التسجيل بنجاح. يرجى تفعيل بريدك الإلكتروني.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $responseData
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء التسجيل'
            ], 500);
        }
    }

    // تفعيل البريد الإلكتروني

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

        if ($user->verifyCode($request->code)) {

            return response()->json([
                'success' => true,
                'message' => 'تم التفعيل بنجاح'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية'
        ], 400);
    }


    // إعادة إرسال كود التفعيل

    public function resendVerificationCode(Request $request)
    {
        $validator = Validator::make($request->all(), ['login' => 'required|string']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = filter_var($request->login, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $request->login)->first()
            : User::where('phone', $request->login)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
        }

        if ($user->isVerified()) {
            return response()->json(['success' => false, 'message' => 'الحساب مفعل مسبقاً'], 400);
        }

        $code = $user->generateVerificationCode();
        $this->sendVerificationCode($user, $code);

        $responseData = [];
        $isEmail = filter_var($request->login, FILTER_VALIDATE_EMAIL);

        if ($isEmail) {
            $message = 'تم إرسال كود التفعيل إلى بريدك الإلكتروني.';
        } else {
            $whatsappUrl = "https://wa.me/{$user->phone}?text=" . urlencode("كود تفعيل حسابك: {$code}");
            $responseData['whatsapp_link'] = $whatsappUrl;
            $message = 'جاري توجيهك إلى واتساب...';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $responseData
        ]);

    }

    // تسجيل الخروج
    public function logout(Request $request)
    {
        $result = $this->authService->logout($request->user());
        return response()->json($result);
    }


    public function profile(Request $request)
    {
        $user = $request->user();

        // تحميل العلاقات
        if ($user->isTeacher()) {
            $user->load('teacher');
        }
        if ($user->isStudent()) {
            $user->load('student');
        }
        if ($user->isGuardian()) {
            $user->load('guardian');
        }


        return response()->json([
            'success' => true,
            'data' => $this->authService->formatUser($user)
        ]);
    }


    // تغيير كلمة المرور
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $this->authService->changePassword(
            $request->user(),
            $request->current_password,
            $request->new_password
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }


    // نسيت كلمة المرور
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
            ? User::where('email', $request-> login)->first()
            : User::where('phone', $request-> login)->first();

        if (!$user) {
            return response()->json([
            'success' => false,
            'message' => 'لم يتم العثور على الحساب'
            ]);
        }
                // ✅ توليد كود وإعادة استخدام نفس حقول التفعيل
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->update([
        'verification_code' => $code,
        'verification_expires_at' => now()->addMinutes(30)
        ]);


            if($user){
                $whatsapp=$this->sendVerificationCode($user , $code);

                if ($whatsapp) {
                    return response()->json([
                        'success' => true,
                        'message'=> 'تم ارسال رابط التفعيل',
                        'data'=>['whatsapp_link'=>$whatsapp]
                    ]);
                }else{
                    return response()->json([
                        'success'=>true,
                        'message'=>'تم ارسال رمز اعادة التعيين لبريدك الالكتروني'
                    ]);
                }
            }
            return response()->json([
            'message'=>'حدث خطا ما']);
        }


    // إعادة تعيين كلمة المرور
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

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    // ========== دوال مساعدة ==========
    // إرسال كود التفعيل (بريد أو SMS)

    private function sendVerificationCode(User $user, string $code)
    {

    //  إذا بريد حقيقي → أرسل إيميل
    if ($user->email) {
        $this->sendEmailCode($user, $code);
        return null; // لا حاجة لرابط واتساب
    }

    //  إذا رقم → أرجع رابط واتساب
    if ($user->phone) {
        return $this->sendSmsCode($user, $code);
    }

    return null;
    }

    private function sendEmailCode(User $user, string $code): void
    {
        $name = $user->isStudent() ? $user->student->student_name :
        ($user->isTeacher() ? $user->teacher->teacher_name :
        ($user->isGuardian() ? $user->guardian->guardian_name : 'مستخدم'));

        $message = "مرحباً {$name}!\n\n";
        $message .= "كود التفعيل: {$code}\n\n";
        $message .= "صالح لمدة 30 دقيقة.\n\n";
        $message .= "نظام إدارة المدرسة";

        Mail::raw($message, function ($mail) use ($user) {
            $mail->to($user->email)->subject('كود التفعيل ');
        });
    }

    private function sendSmsCode(User $user, string $code): string
    {
        // رابط واتساب مجاني
        return  "https://wa.me/{$user->phone}?text=" . urlencode("كود التفعيل: {$code}");

    }

}
