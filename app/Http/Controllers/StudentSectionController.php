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

            $freeSlots = [];

            foreach ($sections as $section) {
                $currentCount = Student::where('section_id', $section->id)->count();
                $freeSlots[$section->id] = max(0, $section->capacity - $currentCount);
            }

            $assigned = [];

            while ($students->isNotEmpty() && array_sum($freeSlots) > 0) {
                foreach ($sections as $section) {
                    if ($students->isEmpty() || $freeSlots[$section->id] < 1) {
                        continue;
                    }

                    $student = $students->shift();
                    $student->section_id = $section->id;
                    $student->save();

                    $freeSlots[$section->id]--;

                    $assigned[] = [
                        'student_id' => $student->id,
                        'student_number' => $student->student_number,
                        'section_id' => $section->id,
                        'section_name' => $section->name,
                    ];
                }
            }

            $unassigned = [];

            foreach ($students as $student) {
                $unassigned[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                ];
            }

            $sectionSummaries = [];

            foreach ($sections as $section) {
                $currentCount = Student::where('section_id', $section->id)->count();

                $sectionSummaries[] = [
                    'section_id' => $section->id,
                    'section_name' => $section->name,
                    'capacity' => $section->capacity,
                    'current_count' => $currentCount,
                    'available_slots' => max(0, $section->capacity - $currentCount),
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

    $targetSection = Section::findOrFail($validated['to_section_id']);
    $students = Student::whereIn('id', $validated['student_ids'])->get();

    $wrongClass = $students->where('class_id', '!=', $targetSection->class_id);

    if ($wrongClass->isNotEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'Some students do not belong to the class of the target section',
            'data' => [
                'target_class_id' => $targetSection->class_id,
                'invalid_student_ids' => $wrongClass->pluck('id')->values(),
            ],
        ], 422);
    }

    $toTransfer = $students->where('section_id', '!=', $targetSection->id);

    $currentTargetCount = Student::where('section_id', $targetSection->id)->count();
    $availableSlots = $targetSection->capacity - $currentTargetCount;

    if ($availableSlots < $toTransfer->count()) {
        return response()->json([
            'success' => false,
            'message' => 'Not enough capacity in target section',
            'data' => [
                'available_slots' => $availableSlots,
                'requested' => $toTransfer->count(),
            ],
        ], 422);
    }

    return DB::transaction(function () use ($toTransfer, $targetSection) {
        $updated = [];

        foreach ($toTransfer as $student) {
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
    });
}
}
