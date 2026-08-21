<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Subject;
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

        $stageError = $this->checkStage(
            $validated['teacher_id'],
            $validated['subject_id'],
            $validated['section_id']
        );

        if ($stageError) {
            return $stageError;
        }

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

        $target = [
            'teacher_id' => $validated['teacher_id'] ?? $teacherAssignment->teacher_id,
            'subject_id' => $validated['subject_id'] ?? $teacherAssignment->subject_id,
            'section_id' => $validated['section_id'] ?? $teacherAssignment->section_id,
        ];

        $stageError = $this->checkStage(
            $target['teacher_id'],
            $target['subject_id'],
            $target['section_id']
        );

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
            'message' => 'تم تحديث التكليف بنجاح',
            'data' => $teacherAssignment->load(['teacher.user', 'subject', 'section.schoolClass']),
        ]);
    }

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

    private function checkStage(int $teacherId, int $subjectId, int $sectionId)
    {
        $teacher = Teacher::with('stage:id,name', 'user:id,user_name')->find($teacherId);
        $section = Section::with('schoolClass.stage')->find($sectionId);

        $class = $section ? $section->schoolClass : null;

        if (!$teacher || !$class || !$teacher->stage_id || !$class->stage_id) {
            return null;
        }

        $stageId = $class->stage_id;
        $classStage = $class->stage ? $class->stage->name : '-';

        if ($teacher->stage_id !== $stageId) {
            $teacherName = $teacher->user ? $teacher->user->user_name : 'المعلم';
            $teacherStage = $teacher->stage ? $teacher->stage->name : '-';

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

        $subject = Subject::find($subjectId);

        if ($subject && !$subject->stages()->where('stages.id', $stageId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "المادة ({$subject->name}) غير مرتبطة بمرحلة ({$classStage})",
                'data' => [
                    'subject_name' => $subject->name,
                    'class_stage' => $classStage,
                    'class_name' => $class->name,
                ],
            ], 422);
        }

        return null;
    }
}
