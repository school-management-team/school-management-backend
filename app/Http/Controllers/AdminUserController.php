<?php
namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Supervisor;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AdminUserController extends Controller
{
    private function pendingUsersByRole(string $role, string $relation, callable $formatDetails)
    {
        $users = User::where('role', $role)
            ->where('status', 'pending')
            ->with($relation)
            ->latest()
            ->get()
            ->map(fn ($user) => array_merge([
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'registered_at' => $user->created_at->format('Y-m-d H:i'),
            ], $formatDetails($user)));

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function pendingStudents()
    {
        $students = User::where('role', 'student')
            ->where('status', 'pending')
            ->with('student.schoolClass.stage')
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
                        'class' => $user->student?->schoolClass?->name,
                        'stage' => $user->student?->schoolClass?->stage?->name,
                        'enrollment_date' => $user->student?->enrollment_date?->format('Y-m-d'),
                    ]
                ];
            });

        return response()->json(['success' => true, 'data' => $students]);
    }

    public function pendingTeachers()
    {
        $teachers = User::where('role', 'teacher')
            ->where('status', 'pending')
            ->with('teacher.subject', 'teacher.stage')
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
                        'subject' => $user->teacher?->subject?->name,
                        'stage' => $user->teacher?->stage?->name,
                        'cv' => $user->teacher?->cv,
                        'legal_document' => $user->teacher?->legal_document_path,
                    ]
                ];
            });

        return response()->json(['success' => true, 'data' => $teachers]);
    }

    public function pendingSupervisors()
    {
        return $this->pendingUsersByRole('supervisor', 'supervisor', fn ($user) => [
            'supervisor_details' => [
                'supervisor_id' => $user->supervisor?->id,
                'specialization' => $user->supervisor?->specialization,
                'bio' => $user->supervisor?->bio,
                'cv_file' => $user->supervisor?->cv_file,
            ],
        ]);
    }

    public function pendingGuardians()
    {
        return $this->pendingUsersByRole('guardian', 'guardian.students', fn ($user) => [
            'guardian_details' => [
                'guardian_id' => $user->guardian?->id,
                'relationship' => $user->guardian?->relationship,
                'number_of_children' => $user->guardian?->number_of_children,
                'linked_students' => $user->guardian
                    ?->students
                    ->map(fn ($student) => [
                        'student_id' => $student->id,
                        'student_number' => $student->student_number,
                        'class' => $student->schoolClass?->name,
                        'father_name' => $student->father_name,
                        'mother_name' => $student->mother_name,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function approveUser($userId)
    {
        $user = User::with(['teacher', 'student.schoolClass', 'guardian', 'supervisor'])->find($userId);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
        }
        if ($user->status === 'active') {
            return response()->json(['success' => false, 'message' => 'الحساب مفعل مسبقاً'], 400);
        }
        if (!$user->isVerified()) {
            return response()->json(['success' => false, 'message' => 'الحساب غير مفعل من قبل المستخدم'], 400);
        }

        if ($user->isStudent()) {
            $gradeOrder = $user->student->schoolClass?->grade_order;
            if (!$gradeOrder) {
                return response()->json(['success' => false, 'message' => 'صف الطالب غير محدد أو غير صالح'], 400);
            }
            try {
                $studentNumber = User::getNextStudentNumberForGrade($gradeOrder);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }

            $user->student->update(['student_number' => $studentNumber]);
        }
        if ($user->isGuardian()) {
            $guardian = $user->guardian;
            $student = Student::where('student_number', $guardian->verification_student_number)->first();
            if (!$student) {
                return response()->json(['success' => false, 'message' => 'تعذر إيجاد الطالب المرتبط، يرجى المراجعة يدويًا'], 400);
            }
            if (!$guardian->students()->where('student_id', $student->id)->exists()) {
                $guardian->students()->attach($student->id);
            }
        }

        $user->update(['status' => 'active']);
        if ($user->email) {
            $this->sendApprovalEmail($user);
        }

        return response()->json(['success' => true, 'message' => 'تمت الموافقة على المستخدم بنجاح']);
    }

    public function rejectUser(Request $request, $userId)
    {
        $user = User::with(['student', 'teacher', 'guardian', 'supervisor'])->find($userId);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
        }

        if ($user->status === 'active') {
            return response()->json(['success' => false, 'message' => 'لا يمكن رفض حساب مفعل'], 400);
        }

        if ($user->status === 'rejected') {
            return response()->json(['success' => false, 'message' => 'لا يمكن رفض حساب مرفوض بالفعل'], 400);
        }

        $reason = $request->reason ?? 'لا يوجد سبب';
        $name = $user->user_name;
        $email = $user->email;

        $user->update(['status' => 'rejected']);

        if ($email) {
            $this->sendRejectionEmail($email, $name, $reason);
        }

        return response()->json(['success' => true, 'message' => 'تم رفض المستخدم بنجاح']);
    }

    public function userDetails($userId)
    {
        $user = User::with([
            'student.guardians', 'student.schoolClass.stage', 'student.section',
            'teacher.subject', 'teacher.stage', 'guardian.students.schoolClass', 'supervisor',
        ])->find($userId);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
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

        if ($user->isStudent() && $user->student) {
            $details['student_details'] = [
                'student_id' => $user->student->id,
                'student_number' => $user->student->student_number,
                'father_name' => $user->student->father_name,
                'mother_name' => $user->student->mother_name,
                'class' => $user->student->schoolClass?->name,
                'stage' => $user->student->schoolClass?->stage?->name,
                'section' => $user->student->section?->name,
                'enrollment_date' => $user->student->enrollment_date,
                'guardians' => $user->student->guardians->map(fn ($g) => [
                    'guardian_id' => $g->id,
                    'name' => $g->user->user_name,
                    'relationship' => $g->relationship,
                ]),
            ];
        }

        if ($user->isTeacher() && $user->teacher) {
            $details['teacher_details'] = [
                'teacher_id' => $user->teacher->id,
                'subject' => $user->teacher->subject?->name,
                'stage' => $user->teacher->stage?->name,
                'cv' => $user->teacher->cv,
                'legal_document' => $user->teacher->legal_document_path,
            ];
        }

        if ($user->isGuardian() && $user->guardian) {
            $details['guardian_details'] = [
                'guardian_id' => $user->guardian->id,
                'relationship' => $user->guardian->relationship,
                'number_of_children' => $user->guardian->number_of_children,
                'children' => $user->guardian->students->map(fn ($s) => [
                    'student_id' => $s->id,
                    'student_number' => $s->student_number,
                    'name' => $s->user->user_name,
                    'class' => $s->schoolClass?->name,
                ]),
            ];
        }

        if ($user->isSupervisor() && $user->supervisor) {
            $details['supervisor_details'] = [
                'supervisor_id' => $user->supervisor->id,
                'specialization' => $user->supervisor->specialization,
                'bio' => $user->supervisor->bio,
            ];
        }

        return response()->json(['success' => true, 'data' => $details]);
    }

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
                'active_supervisors' => User::whereRole('supervisor')->where('status', 'active')->count(),
                'total_guardians' => Guardian::count(),
                'active_guardians' => User::whereRole('guardian')->where('status', 'active')->count(),
                'pending_registrations' => User::where('status', 'pending')
                    ->whereIn('role', ['student', 'teacher', 'supervisor', 'guardian'])
                    ->count(),
            ]
        ]);
    }

    private function sendApprovalEmail(User $user): void
    {
        $roleArabic = match ($user->role) {
            'student' => 'طالب', 'teacher' => 'معلم',
            'guardian' => 'ولي أمر', 'supervisor' => 'موجه',
            default => 'مستخدم'
        };

        $message = "مرحباً {$user->user_name}!\n\n";
        $message .= "تمت الموافقة على تسجيلك كـ {$roleArabic} في النظام.\n\n";

        if ($user->isStudent() && $user->student) {
            $message .= "رقم الطالب: {$user->student->student_number}\n";
            $message .= "الصف: {$user->student->schoolClass?->name}\n\n";
        }

        $message .= "يمكنك الآن تسجيل الدخول واستخدام النظام.\n\nشكراً لك.";

        Mail::raw($message, fn ($mail) => $mail->to($user->email)->subject('تم تفعيل حسابك'));
    }

    private function sendRejectionEmail(string $email, string $name, string $reason): void
    {
        $message = "مرحباً {$name}!\n\nتم رفض طلب تسجيلك في النظام.\n\nالسبب: {$reason}\n\n";
        $message .= "يمكنك التواصل مع الإدارة لمزيد من التفاصيل.";

        Mail::raw($message, fn ($mail) => $mail->to($email)->subject('رفض طلب التسجيل'));
    }

    public function listTeachers()
    {
        $teachers = User::where('role', 'teacher')
            ->where('status', 'active')
            ->with('teacher.subject')
            ->latest()
            ->get()
            ->map(fn ($user) => [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'subject' => $user->teacher?->subject?->name,
            ]);

        return response()->json(['success' => true, 'data' => $teachers]);
    }

    public function listStudents()
    {
        $students = User::where('role', 'student')
            ->where('status', 'active')
            ->with('student.schoolClass')
            ->latest()
            ->get()
            ->map(fn ($user) => [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'class' => $user->student?->schoolClass?->name,
                'section' => $user->student?->section?->name,
                'student_number' => $user->student?->student_number,
            ]);

        return response()->json(['success' => true, 'data' => $students]);
    }

    public function downloadTeacherDocument(Teacher $teacher)
    {
        if (!auth()->user()->isAdmin() && auth()->id() !== $teacher->user_id) {
            abort(403);
        }

        if (!Storage::disk('public')->exists($teacher->legal_document_path)) {
            abort(404, 'الملف غير موجود');
        }

        $fullPath = Storage::disk('public')->path($teacher->legal_document_path);

        return response()->download($fullPath);
    }

    public function lockRegistration(Request $request)
    {
        $setting = SystemSetting::latest()->first();

        if (!$setting) {
            $setting = SystemSetting::create(['registration_locked' => false]);
        }

        if ($setting->registration_locked) {
            return response()->json(['success' => false, 'message' => 'النظام مقفول أصلاً'], 409);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $setting->update([
            'registration_locked' => true,
            'locked_at' => now(),
            'lock_reason' => $request->reason,
        ]);

        return response()->json(['success' => true, 'message' => 'تم إغلاق التسجيل بنجاح']);
    }

    public function unlockRegistration()
    {
        $setting = SystemSetting::latest()->first();

        if (!$setting) {
            $setting = SystemSetting::create(['registration_locked' => false]);
        }

        if (!$setting->registration_locked) {
            return response()->json(['success' => false, 'message' => 'النظام مفتوح أصلاً'], 409);
        }

        $setting->update(['registration_locked' => false, 'locked_at' => null, 'lock_reason' => null]);

        return response()->json(['success' => true, 'message' => 'تم فتح التسجيل بنجاح']);
    }

    public function systemStatus()
    {
        $setting = SystemSetting::latest()->first() ?? new SystemSetting(['registration_locked' => false]);

        return response()->json([
            'success' => true,
            'data' => [
                'registration_locked' => $setting->registration_locked,
                'locked_at' => $setting->locked_at,
                'lock_reason' => $setting->lock_reason,
            ],
        ]);
    }
}
