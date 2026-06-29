<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Supervisor;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;


class AdminController extends Controller
{

// عرض طلبات التسجيل المعلقة
public function pendingStudents()
{
    $students = User::where('role', 'student')
        ->where('status', 'pending')
        ->with('student')
        ->latest()
        ->get()
        ->map(function ($user) {
            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'registered_at' => $user->created_at->format('Y-m-d H:i'),

                'student_details' => [
                    'student_id' => $user->student?->id,
                    'student_number' => $user->student?->student_number,
                    'father_name' => $user->student?->father_name,
                    'mother_name' => $user->student?->mother_name,
                    'education_level' => $user->student?->education_level,
                    'school_class' => $user->student?->school_class,
                    'enrollment_date' => $user->student?->enrollment_date?->format('Y-m-d'),
                ]
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $students
    ]);
}

public function pendingTeachers()
{
    $teachers = User::where('role', 'teacher')
        ->where('status', 'pending')
        ->with('teacher')
        ->latest()
        ->get()
        ->map(function ($user) {
            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'registered_at' => $user->created_at->format('Y-m-d H:i'),

                'teacher_details' => [
                    'teacher_id' => $user->teacher?->id,
                    'education_level' => $user->teacher?->education_level,
                    'school_class' => $user->teacher?->school_class,
                    'specialization' => $user->teacher?->specialization,
                    'cv' => $user->teacher?->cv,
                    'legal_document'=>$user->teacher?->legal_document_path
                ]
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $teachers
    ]);
}

public function pendingSupervisors()
{
    $supervisors = User::where('role', 'supervisor')
        ->where('status', 'pending')
        ->with('supervisor')
        ->latest()
        ->get()
        ->map(function ($user) {
            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'registered_at' => $user->created_at->format('Y-m-d H:i'),

                'supervisor_details' => [
                    'supervisor_id' => $user->supervisor?->id,
                    'specialization' => $user->supervisor?->specialization,
                    'bio' => $user->supervisor?->bio,
                    'cv_file'=>$user->supervisor?->cv_file
                ]
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $supervisors
    ]);
}
public function pendingGuardians()
{
    $guardians = User::where('role', 'guardian')
        ->where('status', 'pending')
        ->with(['guardian.students'])
        ->latest()
        ->get()
        ->map(function ($user) {

            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'registered_at' => $user->created_at->format('Y-m-d H:i'),

                'guardian_details' => [
                    'guardian_id' => $user->guardian?->id,
                    'relationship' => $user->guardian?->relationship,
                    'number_of_children' => $user->guardian?->number_of_children,

                    'linked_students' => $user->guardian?->students->map(function ($student) {
                        return [
                            'student_id' => $student->id,
                            'student_number' => $student->student_number,
                            'school_class' => $student->school_class,
                            'father_name' => $student->father_name,
                            'mother_name' => $student->mother_name,
                        ];
                    })->values()
                ]
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $guardians
    ]);
}
    // الموافقة على مستخدم
   public function approveUser($userId)
   {
    $user = User::with(['teacher','student', 'guardian','supervisor'])->find($userId);

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ],404);
    }

    if ($user->status === 'active') {
        return response()->json([
            'success' => false,
            'message' => 'الحساب مفعل مسبقاً'
        ],400);
    }

    if (!$user->isVerified()) {
        return response()->json([
            'success' => false,
            'message' => 'الحساب غير مفعل من قبل المستخدم'
        ],400);
    }



    if ($user->isStudent()) {

        try {

            $studentNumber = User::getNextStudentNumberForGrade(
                $user->student->school_class
            );

        } catch (\Exception $e) {

            return response()->json([
                'success'=>false,
                'message'=>$e->getMessage()
            ],400);

        }

        $user->student->update([
            'student_number'=>$studentNumber
        ]);
    }
    if($user->isGuardian()){
        $guardian=$user->guardian;
        $studentVerificationNumber=$guardian->verification_student_number;
        $student=Student::where('student_number',$studentVerificationNumber)->first();
        //  ربط الطالب إذا لم يكن مرتبط مسبقاً
        if (!$guardian->students()->where('student_id', $student->id)->exists()) {
            $guardian->students()->attach($student->id);
        }
    }

    $user->update([
        'status'=>'active'
    ]);

    if ($user->email) {
        $this->sendApprovalEmail($user);
    }

    return response()->json([
        'success'=>true,
        'message'=>'تمت الموافقة على المستخدم بنجاح'
    ]);
    }
   public function rejectUser(Request $request, $userId)
   {
    $user = User::with(['student','teacher','guardian','supervisor'])->find($userId);

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ], 404);
    }

    if ($user->status === 'active') {
        return response()->json([
            'success' => false,
            'message' => 'لا يمكن رفض حساب مفعل'
        ], 400);
    }

    if ($user->status === 'rejected') {
        return response()->json([
            'success' => false,
            'message' => 'لا يمكن رفض حساب مرفوض مفعل'
        ], 400);
    }


    $reason = $request->reason ?? 'لا يوجد سبب';

    $name = $user->user_name;
    $email = $user->email;


    $user->update([
        'status' => 'rejected'
    ]);

    if ($email) {
        $this->sendRejectionEmail($email, $name, $reason);
    }

    return response()->json([
        'success' => true,
        'message' => 'تم رفض المستخدم بنجاح'
    ]);
}


    // عرض تفاصيل مستخدم
public function userDetails($userId)
{
    $user = User::with([
        'student.guardians',
        'teacher',
        'guardian.students',
        'supervisor'
    ])->find($userId);

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ], 404);
    }

    $details = [
        'user_id' => $user->id,
        'user_name' => $user->user_name,
        'email' => $user->email,
        'phone' => $user->phone,
        'gender' => $user->gender,
        'birth_date' => $user->birth_date,
        'role' => $user->role,
        'status' => $user->status,
        'created_at' => $user->created_at->format('Y-m-d H:i'),
    ];

    // Student
    if ($user->isStudent() && $user->student) {
        $details['student_details'] = [
            'student_id' => $user->student->id,
            'student_number' => $user->student->student_number,
            'father_name' => $user->student->father_name,
            'mother_name' => $user->student->mother_name,
            'education_level' => $user->student->education_level,
            'school_class' => $user->student->school_class,
            'enrollment_date' => $user->student->enrollment_date,
            'guardians' => $user->student->guardians->map(function ($g) {
                return [
                    'guardian_id' => $g->id,
                    'name' => $g->user->user_name,
                    'relationship' => $g->relationship
                ];
            })
        ];
    }

    // Teacher
    if ($user->isTeacher() && $user->teacher) {
        $details['teacher_details'] = [
            'teacher_id' => $user->teacher->id,
            'education_level' => $user->teacher->education_level,
            'school_class' => $user->teacher->school_class,
            'specialization' => $user->teacher->specialization,
            'cv' => $user->teacher->cv,
            'legal_document' => $user->teacher->legal_document_path,
        ];
    }

    // Guardian
    if ($user->isGuardian() && $user->guardian) {
        $details['guardian_details'] = [
            'guardian_id' => $user->guardian->id,
            'relationship' => $user->guardian->relationship,
            'number_of_children' => $user->guardian->number_of_children,
            'children' => $user->guardian->students->map(function ($s) {
                return [
                    'student_id' => $s->id,
                    'student_number' => $s->student_number,
                    'name' => $s->user->user_name,
                    'class' => $s->school_class
                ];
            })
        ];
    }

    // Supervisor
    if ($user->isSupervisor() && $user->supervisor) {
        $details['supervisor_details'] = [
            'supervisor_id' => $user->supervisor->id,
            'specialization' => $user->supervisor->specialization,
            'bio' => $user->supervisor->bio,
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
                'active_students' => User::whereRole('student')->where('status', 'active')->count(),
                'total_teachers' => Teacher::count(),
                'active_teachers' => User::whereRole('teacher')->where('status', 'active')->count(),
                'total_supervisors' => Supervisor::count(),
                'active_supervisors' =>User::whereRole('supervisor')->where('status', 'active')->count(),
                'total_guardians' => Guardian::count(),
                'active_guardians' => User::whereRole('guardian')->where('status', 'active')->count(),
                'pending_registrations' => User::where('status', 'pending')
                    ->whereIn('role', ['student', 'teacher','supervisor','guardian'])
                    ->count(),

            ]
        ]);
    }
    private function sendApprovalEmail(User $user): void
    {
        $roleArabic = match($user->role) {
        'student' => 'طالب',
        'teacher' => 'معلم',
        'guardian' => 'ولي أمر',
        'supervisor'=>'موجه',
        default => 'مستخدم'
        };

        $message = "مرحباً {$user->user_name}!\n\n";
        $message .= "تمت الموافقة على تسجيلك كـ {$roleArabic} في النظام.\n\n";

        if ($user->isStudent() && $user->student) {
        $message .= "رقم الطالب: {$user->student->student_number}\n";
        $message .= "الصف: {$user->student->school_class}\n\n";
        }

        $message .= "يمكنك الآن تسجيل الدخول واستخدام النظام.\n\n";
        $message .= "شكراً لك.";

        Mail::raw($message, function ($mail) use ($user) {
        $mail->to($user->email)
            ->subject('تم تفعيل حسابك');
        });
    }

    private function sendRejectionEmail(string $email, string $name, string $reason): void
    {
    $message = "مرحباً {$name}!\n\n";
    $message .= "تم رفض طلب تسجيلك في النظام.\n\n";
    $message .= "السبب: {$reason}\n\n";
    $message .= "يمكنك التواصل مع الإدارة لمزيد من التفاصيل.";

    Mail::raw($message, function ($mail) use ($email) {
        $mail->to($email)
            ->subject('رفض طلب التسجيل');
    });
    }

    public function listTeachers()
    {
        $teachers = User::where('role', 'teacher')
        ->where('status', 'active')
        ->with('teacher')
        ->latest()
        ->get()
        ->map(function ($user) {

            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'school_class' => $user->teacher?->school_class,
                'specialization' => $user->teacher?->specialization,
            ];
        });

        return response()->json([
        'success' => true,
        'data' => $teachers
        ]);
    }

    public function listStudents()
    {
        $students = User::where('role', 'student')
        ->where('status', 'active')
        ->with('student')
        ->latest()
        ->get()
        ->map(function ($user) {

            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'school_class' => $user->student?->school_class,
                'student_number' => $user->student?->student_number,
            ];
        });

        return response()->json([
        'success' => true,
        'data' => $students
        ]);
    }
}
