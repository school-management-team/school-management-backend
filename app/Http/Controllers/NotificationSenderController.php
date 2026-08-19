<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Notifications\AcademicDropAlert;
use App\Notifications\ClassAnnouncement;
use App\Notifications\ParentMeetingScheduled;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\Request;

/**
 * نقاط إرسال الإشعارات اليدوية.
 *
 * الإشعارات التلقائية (نشر واجب مثلاً) بتنبعت من مكانها بالكود.
 * هون النقاط اللي بيستعملها المستخدم بشكل مباشر.
 */
class NotificationSenderController extends Controller
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    /**
     * المعلم يبعت إعلاناً لطلاب شعبة بيدرّسها.
     * POST /api/teacher/notifications/class-announcement
     */
    public function classAnnouncement(Request $request)
    {
        $validated = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:2000',
        ]);

        $teacher = $request->user()->teacher;

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد ملف معلم مرتبط بهذا الحساب',
            ], 403);
        }

        // المعلم ما بيبعت إلا لشعبة هو مكلّف فيها
        $teaches = $teacher->assignments()->where('section_id', $validated['section_id'])->exists();

        if (!$teaches) {
            return response()->json([
                'success' => false,
                'message' => 'أنت غير مكلّف بتدريس هذه الشعبة',
            ], 403);
        }

        $section = Section::with('schoolClass')->findOrFail($validated['section_id']);
        $students = $this->dispatcher->to()->studentsOfSection($section->id);

        $sent = $this->dispatcher->send(
            $students,
            new ClassAnnouncement($teacher, $section, $validated['title'], $validated['body'])
        );

        if ($sent === 0) {
            return response()->json([
                'success' => true,
                'message' => 'لا يوجد طلاب في هذه الشعبة، لم يُرسل الإشعار لأحد',
                'data' => ['recipients' => 0],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "تم إرسال الإعلان إلى {$sent} طالب",
            'data' => ['recipients' => $sent, 'section_id' => $section->id],
        ], 201);
    }

    /**
     * الموجّه يبعت تنبيه تراجع دراسي لمعلمي الطالب وأوليائه.
     * POST /api/supervisor/notifications/academic-drop
     */
    public function academicDrop(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'previous_value' => 'required|numeric|min:0|max:100',
            'current_value' => 'required|numeric|min:0|max:100',
            'note' => 'nullable|string|max:500',
            // لمين يوصل التنبيه: افتراضياً للاثنين معاً
            'notify' => 'sometimes|array',
            'notify.*' => 'in:teachers,guardians',
        ]);

        if ($validated['current_value'] >= $validated['previous_value']) {
            return response()->json([
                'success' => false,
                'message' => 'العلامة الحالية ليست أقل من السابقة، لا يوجد تراجع',
            ], 422);
        }

        $student = Student::with('user')->findOrFail($validated['student_id']);
        $subject = Subject::findOrFail($validated['subject_id']);
        $targets = $validated['notify'] ?? ['teachers', 'guardians'];

        $notification = new AcademicDropAlert(
            $student,
            $subject,
            (float) $validated['previous_value'],
            (float) $validated['current_value'],
            $validated['note'] ?? null
        );

        $teachers = 0;
        $guardians = 0;

        if (in_array('teachers', $targets)) {
            $teachers = $this->dispatcher->send(
                $this->dispatcher->to()->teachersOfStudent($student->id),
                $notification
            );
        }

        if (in_array('guardians', $targets)) {
            $guardians = $this->dispatcher->send(
                $this->dispatcher->to()->guardiansOfStudent($student->id),
                $notification
            );
        }

        $total = $teachers + $guardians;

        if ($total === 0) {
            return response()->json([
                'success' => true,
                'message' => 'لا يوجد معلمون أو أولياء أمور مرتبطون بهذا الطالب',
                'data' => ['recipients' => 0, 'teachers' => 0, 'guardians' => 0],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "تم إرسال التنبيه إلى {$total} مستخدم",
            'data' => ['recipients' => $total, 'teachers' => $teachers, 'guardians' => $guardians],
        ], 201);
    }

    /**
     * الموجّه يحدّد موعد اجتماع مع ولي الأمر.
     * POST /api/supervisor/notifications/parent-meeting
     */
    public function parentMeeting(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'meeting_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'meeting_time' => 'required|date_format:H:i',
            'location' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
            'notify_teachers' => 'sometimes|boolean',
        ]);

        $student = Student::with('user')->findOrFail($validated['student_id']);

        $notification = new ParentMeetingScheduled(
            $student,
            $validated['meeting_date'],
            $validated['meeting_time'],
            $validated['location'] ?? null,
            $validated['reason'] ?? null
        );

        $guardians = $this->dispatcher->send(
            $this->dispatcher->to()->guardiansOfStudent($student->id),
            $notification
        );

        $teachers = 0;

        if ($request->boolean('notify_teachers')) {
            $teachers = $this->dispatcher->send(
                $this->dispatcher->to()->teachersOfStudent($student->id),
                $notification
            );
        }

        if ($guardians === 0 && $teachers === 0) {
            return response()->json([
                'success' => true,
                'message' => 'لا يوجد ولي أمر مرتبط بهذا الطالب',
                'data' => ['recipients' => 0, 'guardians' => 0, 'teachers' => 0],
            ]);
        }

        $message = "تم إرسال موعد الاجتماع إلى {$guardians} ولي أمر";

        if ($teachers > 0) {
            $message .= " و{$teachers} معلم";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'recipients' => $guardians + $teachers,
                'guardians' => $guardians,
                'teachers' => $teachers,
            ],
        ], 201);
    }
}
