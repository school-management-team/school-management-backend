<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\WeeklySchedule;
use Illuminate\Http\Request;

class TeacherAssignmentController extends Controller
{
 public function index()
    {
        $assignments = TeacherAssignment::with(['teacher.user', 'subject', 'section.schoolClass'])->get();

        return response()->json([
            'success' => true,
            'data' => $assignments,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
            'subject_id' => 'required|exists:subjects,id',
            'section_id' => 'required|exists:sections,id',
        ]);

        // المعلم بيعطي كذا مادة عادي، بس ضمن مرحلته هو فقط
        $stageError = $this->checkStage($validated['teacher_id'], $validated['section_id']);

        if ($stageError) {
            return $stageError;
        }

        // التكليف موجود؟ منقولها صريحة بدل ما نقول "تم الربط" وما ننشئ شي
        $existing = TeacherAssignment::where($validated)
            ->with('teacher.user:id,user_name', 'subject:id,name', 'section.schoolClass')
            ->first();

        if ($existing) {
            $teacherName = $existing->teacher && $existing->teacher->user
                ? $existing->teacher->user->user_name
                : 'المعلم';

            $subjectName = $existing->subject ? $existing->subject->name : 'هذه المادة';
            $sectionName = $existing->section ? $existing->section->name : '';

            $className = $existing->section && $existing->section->schoolClass
                ? $existing->section->schoolClass->name
                : '';

            return response()->json([
                'success' => false,
                'message' => "{$teacherName} مكلّف أصلاً بتدريس {$subjectName} لشعبة {$sectionName} - {$className}",
                'data' => $existing,
            ], 422);
        }

        $assignment = TeacherAssignment::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'تم ربط المعلم بالمادة والشعبة بنجاح',
            'data' => $assignment->load('teacher.user:id,user_name', 'subject:id,name', 'section.schoolClass'),
        ], 201);
    }

    public function update(Request $request, TeacherAssignment $teacherAssignment)
    {
        $validated = $request->validate([
            'teacher_id' => 'sometimes|exists:teachers,id',
            'subject_id' => 'sometimes|exists:subjects,id',
            'section_id' => 'sometimes|exists:sections,id',
        ]);

        if (count($validated) === 0) {
            return $this->nothingToUpdate();
        }

        // القيم النهائية بعد التعديل، حتى نفحص التكرار قبل ما نحفظ
        $target = [
            'teacher_id' => $validated['teacher_id'] ?? $teacherAssignment->teacher_id,
            'subject_id' => $validated['subject_id'] ?? $teacherAssignment->subject_id,
            'section_id' => $validated['section_id'] ?? $teacherAssignment->section_id,
        ];

        $stageError = $this->checkStage($target['teacher_id'], $target['section_id']);

        if ($stageError) {
            return $stageError;
        }

        $duplicate = TeacherAssignment::where($target)
            ->where('id', '!=', $teacherAssignment->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'يوجد تكليف آخر بنفس المعلم والمادة والشعبة',
            ], 422);
        }

        $teacherAssignment->fill($validated);

        if (!$teacherAssignment->isDirty()) {
            return $this->noChangesMade($teacherAssignment->load('teacher.user:id,user_name', 'subject:id,name', 'section.schoolClass'));
        }

        $teacherAssignment->save();

        return response()->json([
            'success' => true,
            'message' => 'Teacher assignment updated successfully',
            'data' => $teacherAssignment->load(['teacher.user', 'subject', 'section.schoolClass']),
        ]);
    }

    /**
     * المعلم بيدرّس ضمن مرحلته بس — ابتدائي بيعطي صفوف ابتدائي، وما بصير
     * يعطي إعدادي أو ثانوي. عدد المواد مفتوح، المرحلة هي المحصورة.
     *
     * بيرجّع استجابة رفض إذا في مخالفة، وإلا null.
     */
    private function checkStage(int $teacherId, int $sectionId)
    {
        $teacher = Teacher::with('stage:id,name', 'user:id,user_name')->find($teacherId);
        $section = Section::with('schoolClass.stage')->find($sectionId);

        $class = $section ? $section->schoolClass : null;

        // ما فينا نحكم إذا المرحلة ناقصة عند أي طرف
        if (!$teacher || !$class || !$teacher->stage_id || !$class->stage_id) {
            return null;
        }

        if ($teacher->stage_id === $class->stage_id) {
            return null;
        }

        $teacherName = $teacher->user ? $teacher->user->user_name : 'المعلم';
        $teacherStage = $teacher->stage ? $teacher->stage->name : '-';
        $classStage = $class->stage ? $class->stage->name : '-';

        return response()->json([
            'success' => false,
            'message' => "{$teacherName} مرحلته ({$teacherStage}) ولا يمكن تكليفه بصف من مرحلة ({$classStage})",
            'data' => [
                'teacher_stage' => $teacherStage,
                'class_stage' => $classStage,
                'class_name' => $class->name,
            ],
        ], 422);
    }

    /**
     * حذف التكليف بيحذف معه حصصه من الجدول الأسبوعي.
     * فمنوقف ومنقول كم حصة رح تروح، وما منكمّل إلا لما يبعت force=1.
     */
    public function destroy(Request $request, TeacherAssignment $teacherAssignment)
    {
        $lessonsCount = WeeklySchedule::where('teacher_assignment_id', $teacherAssignment->id)->count();

        if ($lessonsCount > 0 && !$request->boolean('force')) {
            return response()->json([
                'success' => false,
                'message' => "هذا التكليف مرتبط بـ {$lessonsCount} حصة في الجدول الأسبوعي، وحذفه سيحذفها معه. أعد الطلب مع force=1 للتأكيد",
                'data' => ['lessons_count' => $lessonsCount],
            ], 409);
        }

        $teacherAssignment->delete();

        $message = 'تم حذف التكليف بنجاح';

        if ($lessonsCount > 0) {
            $message .= " وحُذفت {$lessonsCount} حصة من الجدول الأسبوعي";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['deleted_lessons' => $lessonsCount],
        ]);
    } 
}
