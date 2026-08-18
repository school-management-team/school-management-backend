<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
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
    /**
     * الصف مع كل شعبه ومعلوماتها.
     *
     * لو الصف ما إلو شعب منرجّع نجاح مع مصفوفة فاضية — الصف موجود وهاي
     * نتيجة صحيحة مش خطأ. بس منرجّع معلومات الصف والعدّادات كمان، حتى
     * تقدر الواجهة تعرض "الصف كذا — ما فيه شعب" بدل شاشة فاضية.
     */
    public function sectionsOverview(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:classes,id',
        ]);

        $class = SchoolClass::with('stage:id,name')->findOrFail($request->class_id);

        $found = Section::where('class_id', $class->id)
            ->withCount('students')
            ->orderBy('name')
            ->get();

        $sections = [];
        $totalCapacity = 0;
        $totalStudents = 0;

        foreach ($found as $section) {
            $available = max(0, $section->capacity - $section->students_count);

            $sections[] = [
                'id' => $section->id,
                'name' => $section->name,
                'capacity' => $section->capacity,
                'students_count' => $section->students_count,
                'available_slots' => $available,
                'is_full' => $available === 0,
            ];

            $totalCapacity += $section->capacity;
            $totalStudents += $section->students_count;
        }

        if (count($sections) === 0) {
            $message = 'لا توجد شعب في هذا الصف بعد';
        } else {
            $message = 'عدد الشعب: '.count($sections);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'class' => [
                    'id' => $class->id,
                    'name' => $class->name,
                    'grade_order' => $class->grade_order,
                    'stage' => $class->stage ? $class->stage->name : null,
                ],
                'sections' => $sections,
                'total' => count($sections),
                'total_capacity' => $totalCapacity,
                'total_students' => $totalStudents,
                'available_slots' => max(0, $totalCapacity - $totalStudents),
            ],
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
