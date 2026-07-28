<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Supervisor;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Models\Attendance;
use App\Models\Section;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\TeacherAssignment;



class AdminController extends Controller
{

// عرض طلبات التسجيل المعلقة
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
                    'specialization' => $user->teacher?->specialization,
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
    // الموافقة على مستخدم
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
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
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
    $user = User::with(['student.guardians', 'student.schoolClass.stage',
    'student.section', 'teacher',
    'guardian.students.schoolClass', 'supervisor'])->find($userId);

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

   // قسم الطالب
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

// قسم المعلم
if ($user->isTeacher() && $user->teacher) {
    $details['teacher_details'] = [
        'teacher_id' => $user->teacher->id,
        'specialization' => $user->teacher->specialization,
        'cv' => $user->teacher->cv,
        'legal_document' => $user->teacher->legal_document_path,
    ];
}

// قسم ولي الأمر
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
            $message .= "الصف: {$user->student->schoolClass?->name}\n\n";
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
                'specialization' => $user->teacher?->specialization,
            ];
        });

    return response()->json(['success' => true, 'data' => $teachers]);
}

public function listStudents()
{
    $students = User::where('role', 'student')
        ->where('status', 'active')
        ->with('student.schoolClass')
        ->latest()
        ->get()
        ->map(function ($user) {
            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'class' => $user->student?->schoolClass?->name,
                'section' => $user->student?->section?->name,
                'student_number' => $user->student?->student_number,
            ];
        });

    return response()->json(['success' => true, 'data' => $students]);
}

public function downloadTeacherDocument(Teacher $teacher)
{

    if (!auth()->user()->isAdmin() && auth()->id() !== $teacher->user_id) {
       abort(403);
    }

    // الحصول على المسار الكامل للملف
    $filePath = storage_path('app/' . $teacher->legal_document_path);

    // التحقق من وجود الملف
    if (!file_exists($filePath)) {
        abort(404, 'الملف غير موجود');
    }

    // استخدام response()->download بدلاً من Storage
    return response()->download($filePath);
    }

public function lockRegistration(Request $request)
{
    $setting = SystemSetting::latest()->first();

    if ($setting->registration_locked) {
        return response()->json([
            'success' => false,
            'message' => 'النظام مقفول أصلاً  '
        ], 409); // Conflict
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
        return response()->json([
            'success' => false,
            'message' => 'النظام مفتوح أصلاً',
        ], 409);
    }

    $setting->update([
        'registration_locked' => false,
        'locked_at' => null,
        'lock_reason' => null,
    ]);

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

// 1. حضور يوم معين لشعبة
public function dailyAttendance(Request $request)
{
    $validator = Validator::make($request->all(), [
        'section_id' => 'required|integer|exists:sections,id',
        'date' => 'required|date',
    ]);
    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $section = Section::with('schoolClass')->find($request->section_id);

    $records = Attendance::with('student.user:id,user_name')
        ->where('section_id', $request->section_id)
        ->whereDate('date', $request->date)
        ->get()
        ->map(fn ($a) => [
            'student_id' => $a->student_id,
            'student_name' => $a->student->user->user_name,
            'student_number' => $a->student->student_number,
            'status' => $a->status,
            'excuse' => $a->excuse,
        ]);

    return response()->json([
        'success' => true,
        'data' => [
            'section' => $section->name,
            'class' => $section->schoolClass->name,
            'date' => $request->date,
            'records' => $records,
        ],
    ]);
}

// 2. سجل حضور طالب معين عبر فترة
public function studentAttendanceHistory(Request $request, Student $student)
{
    $validator = Validator::make($request->all(), [
        'from' => 'nullable|date',
        'to' => 'nullable|date|after_or_equal:from',
    ]);
    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $query = $student->attendances()->orderByDesc('date');
    if ($request->from) $query->whereDate('date', '>=', $request->from);
    if ($request->to) $query->whereDate('date', '<=', $request->to);

    $records = $query->get(['date', 'status', 'excuse']);

    return response()->json([
        'success' => true,
        'data' => [
            'student_id' => $student->id,
            'student_name' => $student->user->user_name,
            'summary' => [
                'present' => $records->where('status', 'present')->count(),
                'absent' => $records->where('status', 'absent')->count(),
                'late' => $records->where('status', 'late')->count(),
                'excused' => $records->where('status', 'excused')->count(),
                'total_days' => $records->count(),
            ],
            'records' => $records,
        ],
    ]);
}

// 3. نسبة الحضور لكل شعبة/صف خلال فترة
public function attendanceRateReport(Request $request)
{
    $validator = Validator::make($request->all(), [
        'from' => 'required|date',
        'to' => 'required|date|after_or_equal:from',
        'class_id' => 'nullable|integer|exists:classes,id',
    ]);
    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $query = Attendance::query()
        ->whereBetween('date', [$request->from, $request->to])
        ->join('sections', 'attendance.section_id', '=', 'sections.id')
        ->join('classes', 'sections.class_id', '=', 'classes.id');

    if ($request->class_id) {
        $query->where('classes.id', $request->class_id);
    }

    $stats = $query
        ->selectRaw('sections.id as section_id, sections.name as section_name, classes.name as class_name,
            COUNT(*) as total,
            SUM(CASE WHEN attendance.status = "present" THEN 1 ELSE 0 END) as present_count')
        ->groupBy('sections.id', 'sections.name', 'classes.name')
        ->get()
        ->map(fn ($row) => [
            'section_id' => $row->section_id,
            'section_name' => $row->section_name,
            'class_name' => $row->class_name,
            'attendance_rate' => $row->total > 0 ? round(($row->present_count / $row->total) * 100, 1) : null,
        ]);

    return response()->json(['success' => true, 'data' => $stats]);
}

// 4. الطلاب الأكثر غيابًا
public function mostAbsentStudents(Request $request)
{
    $validator = Validator::make($request->all(), [
        'from' => 'required|date',
        'to' => 'required|date|after_or_equal:from',
        'limit' => 'nullable|integer|min:1|max:100',
    ]);
    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $students = Attendance::query()
        ->whereBetween('date', [$request->from, $request->to])
        ->where('status', 'absent')
        ->selectRaw('student_id, COUNT(*) as absent_count')
        ->groupBy('student_id')
        ->orderByDesc('absent_count')
        ->limit($request->limit ?? 10)
        ->with('student.user:id,user_name')
        ->get()
        ->map(fn ($row) => [
            'student_id' => $row->student_id,
            'student_name' => $row->student->user->user_name,
            'student_number' => $row->student->student_number,
            'absent_count' => $row->absent_count,
        ]);

    return response()->json(['success' => true, 'data' => $students]);
}
//الطلاب اللي لسا ما تعينت لهم شعبة
public function studentsWithoutSection()
{
    $students = Student::whereNull('section_id')
        ->with(['user:id,user_name', 'schoolClass'])
        ->get()
        ->map(fn ($s) => [
            'student_id' => $s->id,
            'student_name' => $s->user->user_name,
            'student_number' => $s->student_number,
            'class' => $s->schoolClass?->name,
        ]);

    return response()->json(['success' => true, 'data' => $students]);

}

public function studentsDistribution()
{
    $distribution = SchoolClass::withCount('students')
        ->orderBy('grade_order')
        ->get()
        ->map(fn ($class) => [
            'class' => $class->name,
            'total_students' => $class->students_count,
        ]);

    return response()->json(['success' => true, 'data' => $distribution]);
}
    /**
     * عرض العلامات المعلقة (draft) لشعبة معينة
     */
    public function pendingSectionGrades(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|integer|exists:subjects,id',
            'section_id' => 'required|integer|exists:sections,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // جلب التعيينات للمادة والشعبة
        $assignmentIds = TeacherAssignment::where('subject_id', $request->subject_id)
            ->where('section_id', $request->section_id)
            ->pluck('id');

        if ($assignmentIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد تعيين معلم لهذه المادة بهذه الشعبة'
            ], 400);
        }

        $subject = Subject::find($request->subject_id);
        $section = Section::with('schoolClass')->find($request->section_id);

        // جلب جميع الطلاب في الشعبة مع علاماتهم
        $students = Student::where('section_id', $request->section_id)
            ->with('user:id,user_name')
            ->get()
            ->map(function ($student) use ($assignmentIds, $subject) {
                $grades = Grade::where('student_id', $student->id)
                    ->whereIn('teacher_assignment_id', $assignmentIds)
                    ->get();

                // تحديد حالة العلامات المجمعة
                $status = 'draft';
                $hasGrades = $grades->isNotEmpty();

                if ($hasGrades) {
                    if ($grades->contains('status', 'rejected')) {
                        $status = 'rejected';
                    } elseif ($grades->every(fn($g) => $g->status === 'approved')) {
                        $status = 'approved';
                    }
                    // إذا كان بعضها approved وبعضها draft => يبقى draft
                }

                return [
                    'student_id' => $student->id,
                    'student_name' => $student->user->user_name,
                    'student_number' => $student->student_number,
                    'has_grades' => $hasGrades,
                    'computed_total' => $grades->sum('value'),
                    'passing_grade' => $subject->passing_grade,
                    'passed' => $grades->sum('value') >= $subject->passing_grade,
                    'status' => $status,
                    'grades_count' => $grades->count(),
                    'items' => $grades->map(fn($g) => [
                        'id' => $g->id,
                        'type' => $g->type,
                        'value' => $g->value,
                        'status' => $g->status,
                    ]),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'section' => $section->name,
                'class' => $section->schoolClass->name,
                'subject' => $subject->name,
                'students' => $students,
                'summary' => [
                    'total_students' => $students->count(),
                    'with_grades' => $students->filter(fn($s) => $s['has_grades'])->count(),
                    'without_grades' => $students->filter(fn($s) => !$s['has_grades'])->count(),
                ]
            ]
        ]);
    }


     // اعتماد علامات شعبة كاملة (فقط العلامات المسودة)

    public function approveSectionGrades(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|integer|exists:subjects,id',
            'section_id' => 'required|integer|exists:sections,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $assignmentIds = TeacherAssignment::where('subject_id', $request->subject_id)
            ->where('section_id', $request->section_id)
            ->pluck('id');

        if ($assignmentIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد تعيين معلم لهذه المادة بهذه الشعبة'
            ], 400);
        }

        // فقط العلامات المسودة (draft) يتم اعتمادها
        $updated = Grade::whereIn('teacher_assignment_id', $assignmentIds)
            ->where('status', 'draft')
            ->update([
                'status' => 'approved',
            ]);

        // عدد العلامات التي كانت مرفوضة ولم يتم اعتمادها
        $rejectedCount = Grade::whereIn('teacher_assignment_id', $assignmentIds)
            ->where('status', 'rejected')
            ->count();

        // عدد العلامات المعتمدة مسبقاً
        $alreadyApproved = Grade::whereIn('teacher_assignment_id', $assignmentIds)
            ->where('status', 'approved')
            ->count();

        $message = "تم اعتماد {$updated} علامة بنجاح";

        if ($rejectedCount > 0) {
            $message .= "، وتبقى {$rejectedCount} علامة مرفوضة تحتاج تعديل";
        }

        if ($alreadyApproved > 0) {
            $message .= "، و{$alreadyApproved} علامة معتمدة مسبقاً";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'approved' => $updated,
                'rejected' => $rejectedCount,
                'already_approved' => $alreadyApproved,
            ]
        ]);
    }


    // رفض علامات شعبة كاملة (فقط العلامات المسودة)

    public function rejectSectionGrades(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|integer|exists:subjects,id',
            'section_id' => 'required|integer|exists:sections,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $assignmentIds = TeacherAssignment::where('subject_id', $request->subject_id)
            ->where('section_id', $request->section_id)
            ->pluck('id');

        if ($assignmentIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد تعيين معلم لهذه المادة بهذه الشعبة'
            ], 400);
        }


        // فقط العلامات المسودة (draft) يتم رفضها
        $updated = Grade::whereIn('teacher_assignment_id', $assignmentIds)
            ->where('status', 'draft')
            ->update([
                'status' => 'rejected',
            ]);

        // عدد العلامات المعتمدة مسبقاً التي لم يتم رفضها
        $alreadyApproved = Grade::whereIn('teacher_assignment_id', $assignmentIds)
            ->where('status', 'approved')
            ->count();

        // عدد العلامات المرفوضة مسبقاً
        $alreadyRejected = Grade::whereIn('teacher_assignment_id', $assignmentIds)
            ->where('status', 'rejected')
            ->count();

        $message = "تم رفض {$updated} علامة بنجاح، بانتظار تعديل المعلم";

        if ($alreadyApproved > 0) {
            $message .= "، وتبقى {$alreadyApproved} علامة معتمدة (لا يمكن رفضها)";
        }

        if ($alreadyRejected > 0) {
            $message .= "، و{$alreadyRejected} علامة مرفوضة مسبقاً";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'rejected' => $updated,
                'already_approved' => $alreadyApproved,
                'already_rejected' => $alreadyRejected,
            ]
        ]);
    }


    // اعتماد علامة فردية

    public function approveSingleGrade(Request $request, $id)
    {
        $grade = Grade::find($id);

        if (!$grade) {
            return response()->json([
                'success' => false,
                'message' => 'العلامة غير موجودة'
            ], 404);
        }

        if ($grade->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'العلامة معتمدة مسبقاً'
            ], 400);
        }

        if ($grade->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن اعتماد علامة مرفوضة، يرجى تعديلها أولاً'
            ], 400);
        }

        $grade->update([
            'status' => 'approved',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم اعتماد العلامة بنجاح',
            'data' => $grade
        ]);
    }


    // إعادة فتح علامة مرفوضة (للمعلم لتعديلها)

    public function reopenGrade($id)
    {
        $grade = Grade::find($id);

        if (!$grade) {
            return response()->json([
                'success' => false,
                'message' => 'العلامة غير موجودة'
            ], 404);
        }

        if ($grade->status !== 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'يمكن فقط إعادة فتح العلامات المرفوضة'
            ], 400);
        }

        $grade->update([
            'status' => 'draft',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إعادة فتح العلامة بنجاح، يمكن للمعلم تعديلها',
            'data' => $grade
        ]);
    }


    // عرض إحصائيات العلامات للمدير

    public function gradeStatistics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'nullable|exists:subjects,id',
            'section_id' => 'nullable|exists:sections,id',
        ]);

        $query = Grade::query();

        if ($request->subject_id) {
            $assignmentIds = TeacherAssignment::where('subject_id', $request->subject_id)->pluck('id');
            $query->whereIn('teacher_assignment_id', $assignmentIds);
        }

        if ($request->section_id) {
            $assignmentIds = TeacherAssignment::where('section_id', $request->section_id)->pluck('id');
            $query->whereIn('teacher_assignment_id', $assignmentIds);
        }

        $total = $query->count();
        $draft = (clone $query)->where('status', 'draft')->count();
        $approved = (clone $query)->where('status', 'approved')->count();
        $rejected = (clone $query)->where('status', 'rejected')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'draft' => $draft,
                'approved' => $approved,
                'rejected' => $rejected,
                'draft_percentage' => $total > 0 ? round(($draft / $total) * 100, 1) : 0,
                'approved_percentage' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
                'rejected_percentage' => $total > 0 ? round(($rejected / $total) * 100, 1) : 0,
            ]
        ]);
    }
public function studentReportCard(Student $student)
{
    $report = $student->grades()
        ->where('status', 'approved')
        ->with('teacherAssignment.subject')
        ->get()
        ->groupBy(fn ($g) => $g->teacherAssignment->subject_id)
        ->map(function ($items) {
            $subject = $items->first()->teacherAssignment->subject;
            $total = $items->sum('value');

            return [
                'subject' => $subject->name,
                'total_value' => $total,
                'passing_grade' => $subject->passing_grade,
                'passed' => $total >= $subject->passing_grade,
            ];
        })
        ->values();

    return response()->json(['success' => true, 'data' => $report]);
}
}

