<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentSectionController extends Controller
{
     public function assign(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'reset' => 'sometimes|boolean',
        ]);

        $classId = $validated['class_id'];
        $reset = $validated['reset'] ?? false;

        return DB::transaction(function () use ($classId, $reset) {
            $sections = Section::where('class_id', $classId)
                ->orderBy('id')
                ->get();

            if ($sections->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sections found for this class',
                ], 404);
            }

            if ($reset) {
                Student::where('class_id', $classId)->update(['section_id' => null]);
            }

            $students = Student::where('class_id', $classId)
                ->whereNull('section_id')
                ->orderBy('id')
                ->get();

            $assigned = [];
            $unassigned = [];
            $sectionSummaries = [];

            foreach ($sections as $section) {
                $currentCount = Student::where('section_id', $section->id)->count();
                $availableSlots = max(0, $section->capacity - $currentCount);

                $sectionSummaries[] = [
                    'section_id' => $section->id,
                    'section_name' => $section->name,
                    'capacity' => $section->capacity,
                    'current_count' => $currentCount,
                    'available_slots' => $availableSlots,
                ];

                while ($availableSlots > 0 && $students->isNotEmpty()) {
                    $student = $students->shift();
                    $student->section_id = $section->id;
                    $student->save();

                    $assigned[] = [
                        'student_id' => $student->id,
                        'student_number' => $student->student_number,
                        'section_id' => $section->id,
                        'section_name' => $section->name,
                    ];

                    $availableSlots--;
                }
            }

            foreach ($students as $student) {
                $unassigned[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => $reset
                    ? 'Students redistributed successfully'
                    : 'Students assigned successfully',
                'data' => [
                    'sections' => $sectionSummaries,
                    'assigned' => $assigned,
                    'unassigned' => $unassigned,
                    'assigned_count' => count($assigned),
                    'unassigned_count' => count($unassigned),
                ],
            ]);
        });
    
    }
     public function sectionsOverview(Request $request)
{
    $validated = $request->validate([
        'class_id' => 'required|exists:classes,id',
    ]);

    $sections = Section::where('class_id', $validated['class_id'])
        ->withCount('students')
        ->orderBy('id')
        ->get()
        ->map(function ($section) {
            return [
                'id' => $section->id,
                'name' => $section->name,
                'capacity' => $section->capacity,
                'students_count' => $section->students_count,
                'available_slots' => max(0, $section->capacity - $section->students_count),
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $sections,
    ]);
}
public function transfer(Request $request)
{
    $validated = $request->validate([
        'student_ids' => 'required|array|min:1',
        'student_ids.*' => 'required|exists:students,id',
        'to_section_id' => 'required|exists:sections,id',
    ]);

    $students = Student::whereIn('id', $validated['student_ids'])->get();
    $targetSection = Section::findOrFail($validated['to_section_id']);

    $requestedCount = $students->count();

    $currentTargetCount = Student::where('section_id', $targetSection->id)->count();
    $availableSlots = $targetSection->capacity - $currentTargetCount;

    if ($availableSlots < $requestedCount) {
        return response()->json([
            'success' => false,
            'message' => 'Not enough capacity in target section',
            'data' => [
                'available_slots' => $availableSlots,
                'requested' => $requestedCount,
            ],
        ], 422);
    }

    $updated = [];

    foreach ($students as $student) {
        $student->section_id = $targetSection->id;
        $student->save();

        $updated[] = [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'new_section_id' => $targetSection->id,
            'new_section_name' => $targetSection->name,
        ];
    }

    return response()->json([
        'success' => true,
        'message' => 'Students transferred successfully',
        'data' => $updated,
    ]);
}
}
