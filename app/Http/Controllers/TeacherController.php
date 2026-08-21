<?php


namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\TeacherAssignment;
use App\Models\TeacherTask;
use App\Models\WeeklySchedule;
use App\Services\AuthService;
use App\Services\GradeService;
use App\Services\TeacherTaskService;
use App\Services\WeeklyScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\AnnouncementService;
use App\Services\AssignmentService;

class TeacherController extends Controller
{



    public function __construct(protected AnnouncementService $announcementService,protected AssignmentService $assignmentService,
    protected TeacherTaskService $teacherTaskService ,protected WeeklyScheduleService $weeklyScheduleService,
     protected GradeService $gradeService,
     protected AuthService $authService,) {}

    // إضافة سؤال (يغطي زر "إضافة سؤال" وأزرار "إضافة سريعة")
    public function storeQuestion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'type' => 'required|in:multiple_choice,true_false,essay',
            'difficulty' => 'required|in:easy,medium,hard',
            'text' => 'required|string|max:2000',
            'model_answer' => 'nullable|required_if:type,essay|string|max:2000',
            'choices' => 'nullable|required_if:type,multiple_choice,true_false|array',
            'choices.*.text' => 'required_if:type,multiple_choice,true_false|string|max:255',
            'choices.*.is_correct' => 'required_if:type,multiple_choice,true_false|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
            $teacherId = $request->user()->teacher->id;

        if (!$this->teacherTeachesSubjectInClass($teacherId, $request->subject_id, $request->class_id)) {
            return response()->json([
            'success' => false,
            'message' => 'أنت لا تدرّس هذه المادة لهذا الصف',
            ], 403);
        }

        $type = $request->type;
        $choices = $request->choices ?? [];

if ($type === 'true_false') {
    if (count($choices) !== 2) {
        return response()->json([
            'success' => false,
            'message' => 'سؤال صح/خطأ يجب أن يحتوي على خيارين بالضبط',
        ], 422);
    }

    $correctCount = 0;
    foreach ($choices as $choice) {
        if (!empty($choice['is_correct'])) {
            $correctCount++;
        }
    }

    if ($correctCount !== 1) {
        return response()->json([
            'success' => false,
            'message' => 'سؤال صح/خطأ يجب أن يحتوي على إجابة صحيحة واحدة بالضبط',
        ], 422);
    }
}
        if ($type === 'multiple_choice' || $type === 'true_false') {
            $hasCorrect = false;
            foreach ($choices as $choice) {
                if (!empty($choice['is_correct'])) {
                    $hasCorrect = true;
                    break;
                }
            }
            if (!$hasCorrect) {
                return response()->json(['success' => false, 'message' => 'يجب تحديد إجابة صحيحة واحدة على الأقل'], 422);
            }
        }

        $question = Question::create([
            'teacher_id' => $request->user()->teacher->id,
            'subject_id' => $request->subject_id,
            'class_id' => $request->class_id,
            'type' => $type,
            'difficulty' => $request->difficulty,
            'text' => $request->text,
            'choices' => $type === 'essay' ? null : $choices,
            'model_answer' => $request->model_answer,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة السؤال بنجاح',
            'data' => $question,
        ], 201);
    }

    // عرض/فلترة/بحث الأسئلة (يغطي: البحث، فلتر المواد، فلتر الصعوبة)
    public function listQuestions(Request $request)
    {
        $query = Question::where('teacher_id', $request->user()->teacher->id);

        if ($request->subject_id) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->class_id) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->difficulty) {
            $query->where('difficulty', $request->difficulty);
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->search) {
            $query->where('text', 'like', '%' . $request->search . '%');
        }

        $questions = $query->with(['subject:id,name', 'schoolClass:id,name'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $questions]);
    }

    // "أحدث الأسئلة" بالواجهة (آخر 3 أسئلة، مع زر "عرض الكل" يستخدم listQuestions)
    public function recentQuestions(Request $request)
    {
        $questions = Question::where('teacher_id', $request->user()->teacher->id)
            ->with(['subject:id,name', 'schoolClass:id,name'])
            ->latest()
            ->limit(3)
            ->get();

        return response()->json(['success' => true, 'data' => $questions]);
    }

    // "نظرة عامة": إجمالي الأسئلة، أسئلة محفوظة، تم إضافتها مؤخراً
    public function questionStats(Request $request)
    {
        $teacherId = $request->user()->teacher->id;

        $total = Question::where('teacher_id', $teacherId)->count();
        $unused = Question::where('teacher_id', $teacherId)->where('usage_count', 0)->count();
        $recentlyAdded = Question::where('teacher_id', $teacherId)->where('created_at', '>=', now()->subDay())->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'unused' => $unused,
                'recently_added' => $recentlyAdded,
            ],
        ]);
    }

    // عرض سؤال واحد بالتفصيل
    public function showQuestion(Request $request, Question $question)
    {
        if ($question->teacher_id !== $request->user()->teacher->id) {
            abort(403);
        }

        $question->load(['subject:id,name', 'schoolClass:id,name']);

        return response()->json(['success' => true, 'data' => $question]);
    }

    // تعديل سؤال
    public function updateQuestion(Request $request, Question $question)
    {
        if ($question->teacher_id !== $request->user()->teacher->id) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'subject_id' => 'sometimes|exists:subjects,id',
            'class_id' => 'sometimes|exists:classes,id',
            'difficulty' => 'sometimes|in:easy,medium,hard',
            'text' => 'sometimes|string|max:2000',
            'model_answer' => 'nullable|string|max:2000',
            'choices' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if ($request->has('subject_id') || $request->has('class_id')) {
            $subjectId = $request->subject_id ?? $question->subject_id;
            $classId = $request->class_id ?? $question->class_id;

            if (!$this->teacherTeachesSubjectInClass($request->user()->teacher->id, $subjectId, $classId)) {
                return response()->json([
                'success' => false,
                'message' => 'أنت لا تدرّس هذه المادة لهذا الصف',
                ], 403);
            }
        }

        $question->update($request->only(['subject_id', 'class_id', 'difficulty', 'text', 'model_answer', 'choices']));

        return response()->json(['success' => true, 'message' => 'تم تحديث السؤال', 'data' => $question]);
    }

    // حذف سؤال
    public function destroyQuestion(Request $request, Question $question)
    {
        if ($question->teacher_id !== $request->user()->teacher->id) {
            abort(403);
        }

        $question->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف السؤال']);
    }
    // أضف هذي التوابع داخل TeacherController الموجود عندك



// عرض/فلترة الإعلانات
public function announcements(Request $request)
{
    $filters = $request->only(['type', 'per_page']);

    if ($request->has('is_important')) {
        $filters['is_important'] = $request->boolean('is_important');
    }

    $announcements = $this->announcementService->list($filters);

    return response()->json(['success' => true, 'data' => $announcements]);
}

// الفعاليات القادمة (تُستخدم بفلتر type=activity)
public function upcomingAnnouncements(Request $request)
{
    $announcements = $this->announcementService->upcoming(
        $request->limit ?? 5,
        $request->type ?? null
    );

    return response()->json(['success' => true, 'data' => $announcements]);
}

// "تواريخ مهمة"
public function importantAnnouncementDates(Request $request)
{
    $dates = $this->announcementService->upcomingImportant($request->limit ?? 2);

    return response()->json(['success' => true, 'data' => $dates]);
}

// نقاط التقويم
public function announcementCalendarDots(Request $request)
{
    $validator = Validator::make($request->all(), [
        'year' => 'required|integer',
        'month' => 'required|integer|min:1|max:12',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $dates = $this->announcementService->calendarDots($request->year, $request->month);

    return response()->json(['success' => true, 'data' => $dates]);
}

// كل إعلانات شهر كامل
public function announcementsForMonth(Request $request)
{
    $validator = Validator::make($request->all(), [
        'year' => 'required|integer',
        'month' => 'required|integer|min:1|max:12',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $announcements = $this->announcementService->forMonth($request->year, $request->month);

    return response()->json(['success' => true, 'data' => $announcements]);
}

// كل إعلانات يوم محدد
public function announcementsForDay(Request $request)
{
    $validator = Validator::make($request->all(), [
        'year' => 'required|integer',
        'month' => 'required|integer|min:1|max:12',
        'day' => 'required|integer|min:1|max:31',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $announcements = $this->announcementService->forDay($request->year, $request->month, $request->day);

    return response()->json(['success' => true, 'data' => $announcements]);
}

// تفاصيل إعلان واحد
public function showAnnouncement($id)
{
    $announcement = $this->announcementService->find($id);

    return response()->json(['success' => true, 'data' => $announcement]);
}

// تعبئة قائمة "اختر المادة"
public function assignmentSubjects(Request $request)
{
    $subjects = $this->assignmentService->subjectsForTeacher($request->user()->teacher);

    return response()->json(['success' => true, 'data' => $subjects]);
}

// تعبئة "الصف والشعبة" بعد اختيار المادة
public function assignmentSections(Request $request, int $subjectId)
{
    $sections = $this->assignmentService->sectionsForSubject($request->user()->teacher, $subjectId);

    return response()->json(['success' => true, 'data' => $sections]);
}

// نشر مهمة جديدة ("نشر في الفصل")
public function storeAssignment(Request $request)
{
    $validator = Validator::make($request->all(), [
        'subject_id' => 'required|exists:subjects,id',
        'section_id' => 'required|exists:sections,id',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:2000',
        'due_date' => 'nullable|date',
        'max_grade' => 'nullable|integer|min:1|max:100',
        'attachment' => 'nullable|file|max:8192',
        'attachment_link' => 'nullable|url',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $teacherAssignment = $this->assignmentService->findTeacherAssignment(
        $request->user()->teacher,
        $request->subject_id,
        $request->section_id
    );

    if (!$teacherAssignment) {
        return response()->json([
            'success' => false,
            'message' => 'أنت لا تدرّس هذه المادة لهذه الشعبة',
        ], 403);
    }

    $attachmentPath = null;
    if ($request->hasFile('attachment')) {
        $attachmentPath = $request->file('attachment')->store('assignments', 'public');
    }

    $assignment = $this->assignmentService->create($teacherAssignment, [
        'title' => $request->title,
        'description' => $request->description,
        'due_date' => $request->due_date,
        'max_grade' => $request->max_grade,
        'attachment_path' => $attachmentPath,
        'attachment_link' => $request->attachment_link,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'تم نشر المهمة في الفصل بنجاح',
        'data' => $assignment,
    ], 201);
}

// عرض كل المهام اللي أنشأها المعلم
public function listAssignments(Request $request)
{
    $assignments = $this->assignmentService->list(
        $request->user()->teacher,
        $request->only(['subject_id', 'section_id', 'per_page'])
    );

    return response()->json(['success' => true, 'data' => $assignments]);
}





// إنشاء مهمة شخصية جديدة
public function storeTask(Request $request)
{
    $validator = Validator::make($request->all(), [
        'subject_id' => 'required|exists:subjects,id',
        'section_id' => 'required|exists:sections,id',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:2000',
        'is_important' => 'boolean',
        'due_date' => 'required|date',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $teacherAssignment = $this->teacherTaskService->findTeacherAssignment(
        $request->user()->teacher, $request->subject_id, $request->section_id
    );

    if (!$teacherAssignment) {
        return response()->json([
            'success' => false,
            'message' => 'أنت لا تدرّس هذه المادة لهذه الشعبة',
        ], 403);
    }

    $task = $this->teacherTaskService->create($teacherAssignment, $request->all());

    return response()->json([
        'success' => true,
        'message' => 'تمت إضافة المهمة بنجاح',
        'data' => $task,
    ], 201);
}

// عرض/فلترة مهام المعلم
public function listTasks(Request $request)
{
    $filters = $request->only(['status', 'subject_id', 'per_page']);

    if ($request->has('is_important')) {
        $filters['is_important'] = $request->boolean('is_important');
    }

    $tasks = $this->teacherTaskService->list($request->user()->teacher, $filters);

    return response()->json(['success' => true, 'data' => $tasks]);
}

// المهام القريبة الاستحقاق
public function upcomingTasks(Request $request)
{
    $tasks = $this->teacherTaskService->upcoming($request->user()->teacher, $request->limit ?? 5);

    return response()->json(['success' => true, 'data' => $tasks]);
}

// تعديل مهمة
public function updateTask(Request $request, TeacherTask $task)
{
    if ($task->teacherAssignment->teacher_id !== $request->user()->teacher->id) {
        abort(403);
    }

    $validator = Validator::make($request->all(), [
        'title' => 'sometimes|string|max:255',
        'description' => 'nullable|string|max:2000',
        'is_important' => 'boolean',
        'due_date' => 'sometimes|date',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $task = $this->teacherTaskService->update(
        $task,
        $request->only(['title', 'description', 'is_important', 'due_date'])
    );

    return response()->json(['success' => true, 'message' => 'تم تحديث المهمة', 'data' => $task]);
}

// حذف مهمة
public function destroyTask(Request $request, TeacherTask $task)
{
    if ($task->teacherAssignment->teacher_id !== $request->user()->teacher->id) {
        abort(403);
    }

    $task->delete();

    return response()->json(['success' => true, 'message' => 'تم حذف المهمة']);
}

// تابع جديد: تغيير حالة المهمة (زر "إنهاء المهمة" / "إعادة فتح")
public function updateTaskStatus(Request $request, TeacherTask $task)
{
    if ($task->teacherAssignment->teacher_id !== $request->user()->teacher->id) {
        abort(403);
    }

    $validator = Validator::make($request->all(), [
        'status' => 'required|in:in_progress,completed',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $task = $this->teacherTaskService->markStatus($task, $request->status);

    return response()->json(['success' => true, 'message' => 'تم تحديث حالة المهمة', 'data' => $task]);
}
// جدول يوم محدد (تبويب "اليوم") — الافتراضي تاريخ اليوم الحالي
public function dailySchedule(Request $request)
{
    $validator = Validator::make($request->all(), [
        'date' => 'nullable|date',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $date = $request->date ?? now()->toDateString();
    $dayOfWeek = strtolower(\Carbon\Carbon::parse($date)->englishDayOfWeek);

    $schedule = $this->weeklyScheduleService->dayOf($request->user()->teacher, $dayOfWeek, $date);

    return response()->json(['success' => true, 'data' => $schedule]);
}

// الجدول الأسبوعي كامل (تبويب "أسبوعي")
public function weeklySchedule(Request $request)
{
    $schedule = $this->weeklyScheduleService->week($request->user()->teacher);

    return response()->json(['success' => true, 'data' => $schedule]);
}

// كتابة خطة الدرس ليوم محدد (زر "خطة الدرس")
public function saveLessonPlan(Request $request, WeeklySchedule $schedule)
{
    if ($schedule->teacher_id !== $request->user()->teacher->id) {
        abort(403);
    }

    $validator = Validator::make($request->all(), [
        'date' => 'required|date',
        'content' => 'required|string|max:2000',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $plan = $this->weeklyScheduleService->saveLessonPlan(
        $schedule,
        $request->input('date'),
        $request->input('content')
    );

    return response()->json(['success' => true, 'message' => 'تم حفظ خطة الدرس', 'data' => $plan]);
}

// لوحة التحكم الرئيسية — تجمّع بيانات من السيرفسين مباشرة
public function dashboard(Request $request)
{
    $teacher = $request->user()->teacher;

    return response()->json([
        'success' => true,
        'data' => [
            'today_classes' => $this->weeklyScheduleService->todaySummary($teacher),
            'pending_tasks' => $this->teacherTaskService->pendingCount($teacher),
        ],
    ]);
}

// بطاقة "إنجازك اليوم" (نسبة الإنجاز)
public function taskProgress(Request $request)
{
    $progress = $this->teacherTaskService->progress($request->user()->teacher);

    return response()->json(['success' => true, 'data' => $progress]);
}

// قائمة المهام مفلترة بالحالة (تبويب "المكتملة" / "قيد التنفيذ")
public function tasksByStatus(Request $request)
{
    $validator = Validator::make($request->all(), [
        'status' => 'required|in:in_progress,completed',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $tasks = $this->teacherTaskService->list(
        $request->user()->teacher,
        ['status' => $request->input('status')]
    );

    return response()->json(['success' => true, 'data' => $tasks]);
}

// زر "تسليم" = إنهاء المهمة
public function submitTask(Request $request, TeacherTask $task)
{
    if ($task->teacherAssignment->teacher_id !== $request->user()->teacher->id) {
        abort(403);
    }

    $task = $this->teacherTaskService->submit($task);

    return response()->json(['success' => true, 'message' => 'تم تسليم المهمة', 'data' => $task]);
}

private function teacherTeachesSubjectInClass(int $teacherId, int $subjectId, int $classId): bool
{
    return TeacherAssignment::where('teacher_id', $teacherId)
        ->where('subject_id', $subjectId)
        ->whereHas('section', function ($sectionQuery) use ($classId) {
            $sectionQuery->where('class_id', $classId);
        })
        ->exists();
}

// إضافة علامة فردية (إجراء سريع)
public function storeGrade(Request $request)
{
    $validator = Validator::make($request->all(), [
        'subject_id' => 'required|exists:subjects,id',
        'section_id' => 'required|exists:sections,id',
        'student_id' => 'required|exists:students,id',
        'type' => 'required|in:participation,quiz,exam',
        'semester' => 'required|integer|in:1,2',
        'value' => 'required|numeric|min:0|max:100',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $teacherAssignment = $this->assignmentService->findTeacherAssignment(
        $request->user()->teacher, $request->input('subject_id'), $request->input('section_id')
    );

    if (!$teacherAssignment) {
        return response()->json(['success' => false, 'message' => 'أنت لا تدرّس هذه المادة لهذه الشعبة'], 403);
    }

    if (!$this->gradeService->studentBelongsToSection($request->input('student_id'), $request->input('section_id'))) {
        return response()->json(['success' => false, 'message' => 'هذا الطالب غير منتمٍ لهذه الشعبة'], 422);
    }

    try {
        $grade = $this->gradeService->store($teacherAssignment, $request->only(['student_id', 'type', 'semester', 'value']));
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
    }

    return response()->json(['success' => true, 'message' => 'تم رصد الدرجة بنجاح', 'data' => $grade], 201);
}

// بطاقات الشعب — واجهة "رصد الدرجات والمهام"
public function mySections(Request $request)
{
    $sections = $this->gradeService->sectionsForTeacher($request->user()->teacher);

    return response()->json(['success' => true, 'data' => $sections]);
}

// فتح فورم رصد الدرجات الجماعي
public function studentsForGrading(Request $request, int $teacherAssignmentId)
{
    $validator = Validator::make($request->all(), [
        'type' => 'required|in:participation,quiz,exam',
        'semester' => 'required|integer|in:1,2',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $teacherAssignment = $this->gradeService->findTeacherAssignmentById(
        $request->user()->teacher, $teacherAssignmentId
    );

    if (!$teacherAssignment) {
        abort(403);
    }

    $students = $this->gradeService->studentsForGrading(
        $teacherAssignment, $request->input('type'), $request->input('semester')
    );

    return response()->json(['success' => true, 'data' => $students]);
}

// حفظ علامات الشعبة دفعة وحدة (رصد جماعي)
public function storeBulkGrades(Request $request, int $teacherAssignmentId)
{
    $validator = Validator::make($request->all(), [
        'type' => 'required|in:participation,quiz,exam',
        'semester' => 'required|integer|in:1,2',
        'grades' => 'required|array|min:1',
        'grades.*.student_id' => 'required|exists:students,id',
        'grades.*.value' => 'required|numeric|min:0|max:100',
    ]);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $teacherAssignment = $this->gradeService->findTeacherAssignmentById(
        $request->user()->teacher, $teacherAssignmentId
    );

    if (!$teacherAssignment) {
        abort(403);
    }

    $outsiders = $this->gradeService->studentsOutsideSection(
        $teacherAssignment, array_column($request->input('grades'), 'student_id')
    );

    if (count($outsiders) > 0) {
        return response()->json([
            'success' => false,
            'message' => 'بعض الطلاب غير منتمين لهذه الشعبة',
            'data' => ['student_ids' => $outsiders],
        ], 422);
    }

    try {
        $this->gradeService->saveBulkGrades(
            $teacherAssignment, $request->input('type'), $request->input('semester'), $request->input('grades')
        );
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
    }

    return response()->json(['success' => true, 'message' => 'تم حفظ الدرجات بنجاح']);
}

// عرض حالة اكتمال/رفع/اعتماد علامات شعبة معينة
public function sectionGradeStatus(Request $request, int $teacherAssignmentId)
{
    $validator = Validator::make($request->all(), ['semester' => 'required|integer|in:1,2']);

    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    $teacherAssignment = $this->gradeService->findTeacherAssignmentById(
        $request->user()->teacher, $teacherAssignmentId
    );

    if (!$teacherAssignment) {
        abort(403);
    }

    $semester = $request->input('semester');
    $isComplete = $this->gradeService->isSectionComplete($teacherAssignment, $semester);
    $isLocked = $this->gradeService->isLocked($teacherAssignment, $semester);

    return response()->json([
        'success' => true,
        'data' => [
            'is_complete' => $isComplete,
            'is_locked' => $isLocked,
            'grades' => $isComplete ? $this->gradeService->computeFinalGrades($teacherAssignment, $semester) : null,
        ],
    ]);
}
public function profile(Request $request)
{
    $user = $request->user();
    $teacher = $user->teacher;

    $baseProfile = $this->authService->formatUser($user);

    $assignments = TeacherAssignment::where('teacher_id', $teacher->id)
        ->with('subject:id,name', 'section.schoolClass:id,name')
        ->get();

    $baseProfile['assignments'] = $assignments;

    return response()->json(['success' => true, 'data' => $baseProfile]);
}
// TeacherController — أضف
public function taskProgressDetails(Request $request)
{
    $tasks = $this->teacherTaskService->todayDetailed($request->user()->teacher);

    return response()->json(['success' => true, 'data' => $tasks]);
}
public function recentActivity(Request $request)
{
    $activities = $this->gradeService->recentActivityForTeacher($request->user()->teacher);

    return response()->json(['success' => true, 'data' => $activities]);
}
}
