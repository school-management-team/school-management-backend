<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
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

        $assignment = TeacherAssignment::where('teacher_id', $validated['teacher_id'])
            ->where('subject_id', $validated['subject_id'])
            ->where('section_id', $validated['section_id'])
            ->first();

        if ($assignment) {
            return response()->json([
                'success' => true,
                'message' => 'Teacher is already assigned to this subject and section',
                'data' => $assignment->load(['teacher.user', 'subject', 'section.schoolClass']),
            ]);
        }

        $assignment = TeacherAssignment::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Teacher assigned successfully',
            'data' => $assignment->load(['teacher.user', 'subject', 'section.schoolClass']),
        ], 201);
    }

    public function update(Request $request, TeacherAssignment $teacherAssignment)
    {
        $validated = $request->validate([
            'teacher_id' => 'sometimes|exists:teachers,id',
            'subject_id' => 'sometimes|exists:subjects,id',
            'section_id' => 'sometimes|exists:sections,id',
        ]);

        $teacherId = $validated['teacher_id'] ?? $teacherAssignment->teacher_id;
        $subjectId = $validated['subject_id'] ?? $teacherAssignment->subject_id;
        $sectionId = $validated['section_id'] ?? $teacherAssignment->section_id;

        $stageError = $this->checkStage($teacherId, $subjectId, $sectionId);

        if ($stageError) {
            return $stageError;
        }

        $exists = TeacherAssignment::where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->where('section_id', $sectionId)
            ->where('id', '!=', $teacherAssignment->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Another assignment with the same teacher, subject and section already exists',
            ], 422);
        }

        $teacherAssignment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Teacher assignment updated successfully',
            'data' => $teacherAssignment->load(['teacher.user', 'subject', 'section.schoolClass']),
        ]);
    }

    public function destroy(TeacherAssignment $teacherAssignment)
    {
        $teacherAssignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Teacher assignment deleted successfully',
        ]);
    }

    private function checkStage($teacherId, $subjectId, $sectionId)
    {
        $section = Section::with('schoolClass')->find($sectionId);
        $stageId = $section->schoolClass->stage_id;

        $teacher = Teacher::find($teacherId);

        if ($teacher->stage_id != $stageId) {
            return response()->json([
                'success' => false,
                'message' => 'Teacher stage does not match the stage of this section',
            ], 422);
        }

        $subject = Subject::find($subjectId);

        if (!$subject->stages()->where('stages.id', $stageId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Subject is not linked to the stage of this section',
            ], 422);
        }

        return null;
    }
}
