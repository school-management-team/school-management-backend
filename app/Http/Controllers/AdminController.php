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
                    'phone' => $user->phone,
                    'registered_at' => $user->created_at->format('Y-m-d H:i'),
                    'student_details' => [
                        'student_id' => $user->student->student_id ?? null,
                        'full_name'=>$user->student->student_name,
                        'grade' => $user->student->grade ?? null,
                        'education_level' => $user->student->education_level ?? null,
                        'birth_date' => $user->student->birth_date?->format('Y-m-d'),
                        'father_name' => $user->student->father_name ?? null,
                        'mother_name' => $user->student->mother_name ?? null
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
                    'phone' => $user->phone,
                    'registered_at' => $user->created_at->format('Y-m-d H:i'),
                    'teacher_details' => [
                        'teacher_id' => $user->teacher->teacher_id ?? null,
                        'full_name'=>$user->teacher->teacher_name,
                        'specialization' => $user->teacher->specialization ?? null,
                        'education_level' => $user->teacher->education_level ?? null,
                        'grade'=>$user->teacher->grade ?? null
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
            'message' => 'لا يمكن الموافقة على المستخدم قبل تفعيل حسابه'
        ], 400);
    }

    // هل المستخدم مفعل مسبقاً؟
    elseif ($user->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم مفعل مسبقاً'
        ], 400);
    }

    if ($user->isStudent() && $user->student) {
        $student = $user->student;
        $grade = $student->grade; // الصف الدراسي (1, 2, 3, ...)

        // ✅ إنشاء رقم مدرسي حسب الصف
        try {
            $studentNumber = Student::getNextStudentNumberForGrade($grade);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // تفعيل المستخدم
    $user->update(['is_active' => true]);

    // تحديث حالة الملف الشخصي
    if ($user->isTeacher() && $user->teacher) {
        $user->teacher->update(['status' => 'active']);
    } elseif ($user->isStudent() && $user->student) {
        $user->student->update(['status' => 'active', 'student_number' => $studentNumber]);
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
        $name = '';

        // حذف الملف الشخصي
        if ($user->teacher) {
            $name=$user->teacher->teacher_name;
            $user->teacher->delete();
        } elseif ($user->student) {
            $name=$user->student->student_name;
            $user->student->delete();
        }elseif($user->guardian){
            $name=$user->guardian->guardian_name;
            $user->guardian->delete();
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

    $user = User::with(['teacher', 'student', 'student.guardians'])
        ->findOrFail($userId);

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'لم يتم العثور على المستخدم'
        ], 404);
    }

    $details = [
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => $user->role,
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
            'teacher_id' => $user->teacher->id,
            'full_name' => $user->teacher->teacher_name,
            'specialization' => $user->teacher->specialization,
            'education_level' => $user->teacher->education_level,
            'status' => $user->teacher->status,
        ];
    } elseif ($user->isStudent() && $user->student) {
        $details['student_details'] = [
            'student_id' => $user->student->id,
            'full_name' => $user->student->student_name,
            'grade' => $user->student->grade,
            'education_level' => $user->student->education_level,
            'status' => $user->student->status,
            'father_name' => $user->student->father_name,
            'mother_name' => $user->student->mother_name,
            'birth_date' => $user->student->birth_date ,
            'gender' => $user->student->gender,
            'enrollment_date' => $user->student->enrollment_date,
        ];

        //  إضافة أولياء الأمور للطالب
        if ($user->student->guardians()->exists()) {
            $details['student_details']['guardians'] = $user->student->guardians->map(function ($guardian) {
                return [
                    'guardian_id' => $guardian->id,
                    'guardian_name' => $guardian->guardian_name,
                    'relationship' => $guardian->pivot->relationship ?? 'غير محدد',
                    'is_primary' => (bool)($guardian->pivot->is_primary ?? false),
                    'status' => $guardian->status,
                    'number_of_children' => $guardian->number_of_children,
                ];
            });

            //  إضافة ولي الأمر الأساسي كحقل منفصل
            $primaryGuardian = $user->student->guardians()
                ->wherePivot('is_primary', true)
                ->first();

            if ($primaryGuardian) {
                $details['student_details']['primary_guardian'] = [
                    'guardian_id' => $primaryGuardian->id,
                    'guardian_name' => $primaryGuardian->guardian_name,
                    'relationship' => $primaryGuardian->pivot->relationship,
                ];
            }
        } else {
            $details['student_details']['guardians'] = []; // لا يوجد أولياء أمر
        }
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

        if ($user->isStudent() ) {
        $message .= "📚 رقمك المدرسي هو: **{$user->student->studentNumber}**\n";
        $message .= "يمكنك استخدام هذا الرقم للتعريف بنفسك داخل المدرسة.\n\n";
        }

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
