<?php


namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;


class AdminController extends Controller
{

    // عرض طلبات التسجيل المعلقة
    public function pendingRegistrations()
    {
        $pendingStudents = User::where('role', 'student')
            ->where('is_active', false)
            ->whereNotNull('email_verified_at')
            ->with('student')
            ->latest()
            ->get()
            ->map(function ($user) {
                return [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'full_name' => $user->full_name,
                    'phone' => $user->phone,
                    'registered_at' => $user->created_at->format('Y-m-d H:i'),
                    'student_details' => [
                        'student_id' => $user->student->student_id ?? null,
                        'grade' => $user->student->grade ?? null,
                        'education_level' => $user->student->education_level ?? null,
                        'birth_date' => $user->student->birth_date?->format('Y-m-d'),
                        'father_name' => $user->student->father_name ?? null,
                        'mother_name' => $user->student->mother_name ?? null,
                        'guardian_phone' => $user->student->guardian_phone ?? null,
                        'address' => $user->student->address ?? null,
                        'health_status' => $user->student->health_status ?? null,
                    ]
                ];
            });

        $pendingTeachers = User::where('role', 'teacher')
            ->where('is_active', false)
            ->whereNotNull('email_verified_at')
            ->with('teacher')
            ->latest()
            ->get()
            ->map(function ($user) {
                return [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'full_name' => $user->full_name,
                    'phone' => $user->phone,
                    'registered_at' => $user->created_at->format('Y-m-d H:i'),
                    'teacher_details' => [
                        'teacher_id' => $user->teacher->teacher_id ?? null,
                        'specialization' => $user->teacher->specialization ?? null,
                        'education_level' => $user->teacher->education_level ?? null,
                        'years_of_experience' => $user->teacher->years_of_experience ?? null,
                        'national_id' => $user->teacher->national_id ?? null,
                        'address' => $user->teacher->address ?? null,
                        'health_status' => $user->teacher->health_status ?? null,
                    ]
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'students' => $pendingStudents,
                'teachers' => $pendingTeachers,
                'total_pending' => $pendingStudents->count() + $pendingTeachers->count()
            ]
        ]);
    }

    // الموافقة على مستخدم
    public function approveUser($userId)
    {
    $user = User::find($userId);

    if(!$user) {
        return response()->json([
            'success' => false,
            'message' => 'لم يتم العثور على بيانات المستخدم'
        ], 404);
    }

    //  هل قام المستخدم بتفعيل بريده الإلكتروني
    elseif (!$user->isVerified()) {
        return response()->json([
            'success' => false,
            'message' => 'لا يمكن الموافقة على المستخدم قبل تفعيل بريده الإلكتروني'
        ], 400);
    }

    // هل المستخدم مفعل مسبقاً؟
    elseif ($user->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم مفعل مسبقاً'
        ], 400);
    }

    // تفعيل المستخدم
    $user->update(['is_active' => true]);

    // تحديث حالة الملف الشخصي
    if ($user->isTeacher() && $user->teacher) {
        $user->teacher->update(['status' => 'active']);
    } elseif ($user->isStudent() && $user->student) {
        $user->student->update(['status' => 'active']);
    }

    // إرسال إيميل تفعيل
    $this->sendApprovalEmail($user);

    return response()->json([
        'success' => true,
        'message' => 'تمت الموافقة على المستخدم بنجاح'
    ]);
    }



    // رفض مستخدم
    public function rejectUser(Request $request, $userId)
    {
        $user = User::find($userId);
        if(!$user)
        {
            return response()->json([
            'success' => false,
            'message' => 'لم يتم العثور على المستخدم'
            ]);
        }
        if ($user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن رفض مستخدم مفعل'
            ], 400);
        }

        if($request->reason){
            $reason = $request->reason;
        }else{
            $reason='لا يوجد سبب';
        }

        // حفظ البريد والاسم قبل الحذف
        $email = $user->email;
        $name = $user->full_name;

        // حذف الملف الشخصي
        if ($user->teacher) {
            $user->teacher->delete();
        } elseif ($user->student) {
            $user->student->delete();
        }

        // حذف المستخدم
        $user->delete();

        // إرسال إيميل رفض
        $this->sendRejectionEmail($email, $name, $reason);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض المستخدم بنجاح'
        ]);
    }


    // عرض تفاصيل مستخدم
    public function userDetails($userId)
    {
        $user = User::with(['teacher', 'student'])
            ->findOrFail($userId);

            if(!$user)
            {
                return response()->json([
                'success' => false,
                'message' => 'لم يتم العثور على المستخدم'
                ]);
            }

            $details = [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'registered_at' => $user->created_at->format('Y-m-d H:i'),
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i'),
            ];

            if ($user->isAdmin()) {
                $details['admin_details'] = [
                'type' => 'مدير النظام',
                'access_level' => 'كامل',
                'can_manage' => ['teachers', 'students', 'system']
                ];
                return response()->json([
                'success' => true,
                'data' => $details
                ]);
            }

            if ($user->isTeacher() && $user->teacher) {
                $details['teacher_details'] = [
                'teacher_id' => $user->teacher->teacher_id,
                'specialization' => $user->teacher->specialization,
                'education_level' => $user->teacher->education_level,
                'years_of_experience' => $user->teacher->years_of_experience,
                'status' => $user->teacher->status,
                'national_id' => $user->teacher->national_id,
                'address' => $user->teacher->address,
                'health_status' => $user->teacher->health_status,
                ];
            } elseif ($user->isStudent() && $user->student) {
                $details['student_details'] = [
                'student_id' => $user->student->student_id,
                'grade' => $user->student->grade,
                'section' => $user->student->section,
                'education_level' => $user->student->education_level,
                'status' => $user->student->status,
                'father_name' => $user->student->father_name,
                'mother_name' => $user->student->mother_name,
                'guardian_phone' => $user->student->guardian_phone,
                'guardian_email' => $user->student->guardian_email,
                'address' => $user->student->address,
                'health_status' => $user->student->health_status,
                'wallet_balance' => $user->student->wallet_balance,
                ];
            }

        return response()->json([
            'success' => true,
            'data' => $details
        ]);
    }


    // إحصائيات سريعة للمدير
    public function dashboardStats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total_students' => Student::count(),
                'active_students' => Student::where('status', 'active')->count(),
                'total_teachers' => Teacher::count(),
                'active_teachers' => Teacher::where('status', 'active')->count(),
                'pending_registrations' => User::where('is_active', false)
                    ->whereIn('role', ['student', 'teacher'])
                    ->count(),
                'pending_students' => User::where('role', 'student')->where('is_active', false)->count(),
                'pending_teachers' => User::where('role', 'teacher')->where('is_active', false)->count(),
            ]
        ]);
    }



    private function sendApprovalEmail(User $user): void
    {
        $roleArabic = match($user->role) {
        'teacher' => 'معلم',
        'student' => 'طالب',
        default => 'مستخدم'
        };

        $message = "مرحباً {$user->full_name}!\n\n";
        $message .= "🎉 تمت الموافقة على طلب تسجيلك {$roleArabic} في نظام إدارة المدرسة.\n\n";
        $message .= "✅ بريدك الإلكتروني مفعل.\n";
        $message .= "✅ تمت الموافقة على طلبك من قبل الإدارة.\n\n";
        $message .= "يمكنك الآن تسجيل الدخول إلى حسابك والبدء في استخدام النظام.\n\n";
        $message .= "نتمنى لك التوفيق!\n\n";
        $message .= "--\nإدارة المدرسة";

        Mail::raw($message, function ($mail) use ($user) {
            $mail->to($user->email)
            ->subject('🎉 تم تفعيل حسابك - نظام إدارة المدرسة');
        });
    }

    private function sendRejectionEmail(string $email, string $name, string $reason): void
    {
        $message = "مرحباً {$name}!\n\n";
        $message .= "نأسف لإعلامك بأنه تم رفض طلب تسجيلك في نظام إدارة المدرسة.\n\n";
        $message .= "سبب الرفض: {$reason}\n\n";
        $message .= "يمكنك التواصل مع الإدارة لمزيد من المعلومات.\n\n";
        $message .= "--\nإدارة المدرسة";

        Mail::raw($message, function ($mail) use ($email) {
            $mail->to($email)
                 ->subject('بخصوص طلب التسجيل - نظام إدارة المدرسة');
        });
    }

    public function listTeachers()
    {
    $teachers = User::where('role', 'teacher')
        ->where('is_active', true)
        ->with('teacher')
        ->get();

    return response()->json(['success' => true, 'data' => $teachers]);
    }

    public function listStudents()
    {
        $students = User::where('role', 'student')
        ->where('is_active', true)
        ->with('student')
        ->get();

        return response()->json(['success' => true, 'data' => $students]);
    }
}
