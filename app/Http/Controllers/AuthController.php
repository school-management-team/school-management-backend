<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\LoginHistory;
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
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
            'device_type' => 'nullable|string',
            'device_token' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $deviceInfo = [
            'device_name' => $request->device_name ?? $request->userAgent(),
            'device_type' => $request->device_type ?? 'web',
            'device_token' => $request->device_token
        ];

        $result = $this->authService->login($request->email, $request->password, $deviceInfo);

        $statusCode = $result['success'] ? 200 : 401;
        return response()->json($result, $statusCode);
    }


    // تسجيل طالب جديد


    public function registerStudent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string|max:50',
            'father_name' => 'required|string|max:50',
            'mother_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:50',
            'birth_date' => 'required|date|after_or_equal:2008-01-01|before_or_equal:2019-12-31',
            'gender' => 'required|in:male,female',
            'education_level' => 'required|in:primary,middle,high',
            'grade' => 'required|integer|between:1,12',
            'address' => 'required|string',
            'guardian_phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $student = Student::create([
                'first_name' => $request->first_name,
                'father_name' => $request->father_name,
                'mother_name' => $request->mother_name,
                'last_name' => $request->last_name,
                'student_id' => $this->generateStudentId(),
                'birth_date' => $request->birth_date,
                'gender' => $request->gender,
                'education_level' => $request->education_level,
                'grade' => $request->grade,
                'address' => $request->address,
                'guardian_phone' => $request->guardian_phone,
                'status' => 'unverified',
                'enrollment_date' => now(),
                'wallet_balance' => 0
            ]);

            if (!$student || !$student->id) {
                throw new \Exception('فشل في إنشاء سجل الطالب');
            }

            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'student',
                'phone' => $request->guardian_phone,
                'student_id' => $student->id,
                'is_active' => false,
                'password_changed_at' => now()
            ]);

            if (!$user || !$user->id) {
                throw new \Exception('فشل في إنشاء سجل المستخدم');
            }

            //  إرسال كود تفعيل البريد
            $code = $user->generateVerificationCode();
            $this->sendVerificationEmail($user, $code);

            // إرسال إيميل استلام الطلب
            $this->sendRegistrationReceivedEmail($user);

            return response()->json([
                'success' => true,
                'message' => 'تم التسجيل بنجاح. يرجى تفعيل بريدك الإلكتروني. بعد التفعيل، سيتم مراجعة طلبك من قبل الإدارة.',
                'data' => [
                    'user_id' => $user->id,
                    'student_id' => $student->student_id,
                    'requires_verification' => true,
                    'status' => 'pending_verification'
                ]
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
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:50',
            'phone' => 'required|string',
            'national_id' => 'required|string|unique:teachers',
            'birth_date' => 'required|date|before:-22 years',
            'gender' => 'required|in:male,female',
            'specialization' => 'required|string',
            'education_level' => 'required|in:primary,middle,high',
            'years_of_experience' => 'required|integer|min:0',
            'weekly_hours' => 'required|integer|min:1|max:40',
            'address' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $teacher = Teacher::create([
                'first_name' => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name' => $request->last_name,
                'teacher_id' => $this->generateTeacherId(),
                'national_id' => $request->national_id,
                'birth_date' => $request->birth_date,
                'gender' => $request->gender,
                'address' => $request->address,
                'health_status' => $request->health_status ?? null,
                'specialization' => $request->specialization,
                'education_level' => $request->education_level,
                'years_of_experience' => $request->years_of_experience,
                'weekly_hours' => $request->weekly_hours,
                'hire_date' => now(),
                'status' => 'unverified'
            ]);

            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'teacher',
                'phone' => $request->phone,
                'teacher_id' => $teacher->id,
                'is_active' => false,
                'password_changed_at' => now()
            ]);

            if (!$user || !$user->id) {
                throw new \Exception('فشل في إنشاء سجل المستخدم');
            }

            //  إرسال كود تفعيل البريد
            $code = $user->generateVerificationCode();
            $this->sendVerificationEmail($user, $code);

            // إرسال إيميل استلام الطلب
            $this->sendRegistrationReceivedEmail($user);

            return response()->json([
                'success' => true,
                'message' => 'تم التسجيل بنجاح. يرجى تفعيل بريدك الإلكتروني. بعد التفعيل، سيتم مراجعة طلبك من قبل الإدارة.',
                'data' => [
                    'user_id' => $user->id,
                    'teacher_id' => $teacher->teacher_id,
                    'requires_verification' => true,
                    'status' => 'pending_verification'
                ]
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
            'email' => 'required|email',
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

        if ($user->verifyCode($request->code)) {

            return response()->json([
                'success' => true,
                'message' => 'تم تفعيل البريد الإلكتروني بنجاح'
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

        if ($user->isVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'البريد مفعل مسبقاً'
            ], 400);
        }

        $code = $user->generateVerificationCode();
        $this->sendVerificationEmail($user, $code);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال كود تفعيل جديد'
        ]);
    }



    // تسجيل الخروج

    public function logout(Request $request)
    {
        $allDevices = $request->input('all_devices', false);
        $result = $this->authService->logout($request->user(), $allDevices);
        return response()->json($result);
    }


    // الملف الشخصي
    public function profile(Request $request)
    {
        $user = $request->user();

        if ($user->isTeacher()) {
            $user->load('teacher');
        }
        if ($user->isStudent()) {
            $user->load('student');
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatUserResponse($user)
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
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $this->authService->requestPasswordReset($request->email);

        if (isset($result['data']['token'])) {
            $user = User::where('email', $request->email)->first();
            if ($user) {
                $this->sendPasswordResetEmail($user, $result['data']['token'], $result['data']['code']);
            }
        }

        return response()->json($result);
    }


    // إعادة تعيين كلمة المرور

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
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
            $request->token,
            $request->code,
            $request->password
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }


    // فحص قوة كلمة المرور

    public function checkPasswordStrength(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $passwordService = new PasswordStrengthService();
        $result = $passwordService->validate($request->password);

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }


    // الأجهزة النشطة

    public function activeDevices(Request $request)
    {
        $result = $this->authService->getActiveDevices($request->user());
        return response()->json($result);
    }


    // إلغاء جهاز
    public function revokeDevice(Request $request, string $tokenId)
    {
        $result = $this->authService->revokeDevice($request->user(), $tokenId);
        return response()->json($result, $result['success'] ? 200 : 400);
    }


    private function generateStudentId(): string
    {
        $year = date('Y');
        $count = Student::whereYear('created_at', $year)->count() + 1;
        return 'STU' . $year . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function generateTeacherId(): string
    {
        $year = date('Y');
        $count = Teacher::whereYear('created_at', $year)->count() + 1;
        return 'TCH' . $year . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function sendVerificationEmail(User $user, string $code): void
    {
        $message = "مرحباً {$user->full_name}!\n\n";
        $message .= "كود تفعيل البريد الإلكتروني: {$code}\n\n";
        $message .= "هذا الكود صالح لمدة 30 دقيقة.\n\n";
        $message .= "نظام إدارة المدرسة";

        Mail::raw($message, function ($mail) use ($user) {
            $mail->to($user->email)->subject('تفعيل البريد الإلكتروني');
        });
    }

    private function sendRegistrationReceivedEmail(User $user): void
    {
       $role = $user->isTeacher() ? 'معلم' : 'طالب';

        $message = "مرحباً {$user->full_name}!\n\n";
        $message .= "تم استلام طلب تسجيلك {$role} في نظام إدارة المدرسة بنجاح.\n\n";
        $message .= "✅ الخطوة 1: يرجى تفعيل بريدك الإلكتروني أولاً باستخدام الكود المرسل إليك.\n";
        $message .= "✅ الخطوة 2: بعد تفعيل بريدك، سيتم مراجعة طلبك من قبل الإدارة.\n\n";
        $message .= "شكراً لصبرك!\n\n";
        $message .= "نظام إدارة المدرسة";

        Mail::raw($message, function ($mail) use ($user) {
            $mail->to($user->email)->subject('تم استلام طلب التسجيل - يرجى تفعيل بريدك');
        });
    }

    private function sendPasswordResetEmail(User $user, string $token, string $code): void
    {
        $message = "مرحباً {$user->full_name}!\n\n";
        $message .= "كود إعادة تعيين كلمة المرور: {$code}\n\n";
        $message .= "هذا الكود صالح لمدة ساعتين.\n\n";
        $message .= "إذا لم تطلب إعادة تعيين كلمة المرور، تجاهل هذا البريد.\n\n";
        $message .= "نظام إدارة المدرسة";

        Mail::raw($message, function ($mail) use ($user) {
            $mail->to($user->email)->subject('إعادة تعيين كلمة المرور');
        });
    }

    private function formatUserResponse(User $user): array
    {
        $response = [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'full_name' => $user->full_name,
            'email_verified' => $user->isVerified()
        ];
        if ($user->isAdmin()) {
            $response['profile'] = [
                'role' => 'مدير النظام',
                'access_level' => 'full'
            ];
            return $response;
        }

        if ($user->isTeacher() && $user->teacher) {
            $response['profile'] = [
                'teacher_id' => $user->teacher->teacher_id,
                'specialization' => $user->teacher->specialization,
                'education_level' => $user->teacher->education_level,
                'status' => $user->teacher->status
            ];
        }
        if ($user->isStudent() && $user->student) {
            $response['profile'] = [
                'student_id' => $user->student->student_id,
                'education_level' => $user->student->education_level,
                'grade' => $user->student->grade,
                'section' => $user->student->section,
                'status' => $user->student->status,
                'wallet_balance' => $user->student->wallet_balance
            ];
        }

        return $response;
    }


    // سجل محاولات الدخول (جديد)

    public function loginHistory(Request $request)
    {
        $user = $request->user();

        $history = LoginHistory::byUser($user->id)
            ->latest()
            ->take(50)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'date' => $log->created_at->format('Y-m-d H:i'),
                    'time_ago' => $log->created_at->diffForHumans(),
                    'ip' => $log->ip_address,
                    'device' => $log->device_type,
                    'browser' => $log->user_agent,
                    'status' => $log->status,
                    'status_icon' => match($log->status) {
                        'success' => '✅',
                        'failed' => '❌',
                        'locked' => '🔒',
                        'auto_logout' => '⏰',
                        default => '❓'
                    },
                    'status_text' => match($log->status) {
                        'success' => 'دخول ناجح',
                        'failed' => 'محاولة فاشلة',
                        'locked' => 'حساب مقفل',
                        'auto_logout' => 'خروج تلقائي',
                        default => 'غير معروف'
                    }
                ];
            });

            // إحصائيات
            $stats = [
                'total_logins' => LoginHistory::where('user_id', $user->id)->successful()->count(),
                'failed_attempts' => LoginHistory::where('user_id', $user->id)->failed()->count(),
                'today_logins' => LoginHistory::where('user_id', $user->id)->successful()->today()->count(),
                'last_login' => LoginHistory::where('user_id', $user->id)->successful()->latest()->first()?->created_at?->diffForHumans(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'history' => $history,
                'stats' => $stats
            ]
        ]);
    }


}
