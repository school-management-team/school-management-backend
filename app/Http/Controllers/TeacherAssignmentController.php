<?php

namespace App\Http\Controllers;

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

        $assignment = TeacherAssignment::firstOrCreate($validated);

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
}
