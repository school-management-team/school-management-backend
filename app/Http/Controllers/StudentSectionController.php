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
                    'message' => 'لا توجد شعب في هذا الصف',
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

            /*
             | الرسالة لازم تعكس اللي صار فعلاً.
             |
             | لما يكون كل الطلاب موزّعين مسبقاً، ما بينوزّع ولا واحد —
             | فما بصير نقول "تم التوزيع بنجاح" ونحنا ما لمسنا ولا صف.
             */
            $assignedCount = count($assigned);
            $unassignedCount = count($unassigned);
            $totalSeats = 0;
            $takenSeats = 0;

            foreach ($sectionSummaries as $summary) {
                $totalSeats += $summary['capacity'];
                $takenSeats += $summary['current_count'];
            }

            if ($assignedCount === 0 && $unassignedCount === 0) {
                $message = $reset
                    ? 'لا يوجد طلاب في هذا الصف لإعادة توزيعهم'
                    : 'جميع طلاب هذا الصف موزّعون على الشعب مسبقاً';
            } elseif ($assignedCount === 0) {
                $message = "لم يُوزَّع أي طالب — لا توجد مقاعد شاغرة، وبقي {$unassignedCount} طالب بلا شعبة";
            } else {
                $message = $reset
                    ? "تم إعادة توزيع {$assignedCount} طالب"
                    : "تم توزيع {$assignedCount} طالب";

                if ($unassignedCount > 0) {
                    $message .= "، وبقي {$unassignedCount} بلا شعبة لامتلاء المقاعد";
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'changed' => $assignedCount > 0,
                'data' => [
                    'sections' => $sectionSummaries,
                    'assigned' => $assigned,
                    'unassigned' => $unassigned,
                    'assigned_count' => $assignedCount,
                    'unassigned_count' => $unassignedCount,
                    'total_capacity' => $totalSeats,
                    'total_students' => $takenSeats,
                    'available_slots' => max(0, $totalSeats - $takenSeats),
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

    $targetSection = Section::findOrFail($validated['to_section_id']);
    $students = Student::whereIn('id', $validated['student_ids'])->get();

    $wrongClass = $students->where('class_id', '!=', $targetSection->class_id);

    if ($wrongClass->isNotEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'بعض الطلاب لا ينتمون لصف الشعبة المستهدفة',
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
            'message' => 'لا تتسع الشعبة المستهدفة لهذا العدد',
            'data' => [
                'available_slots' => $availableSlots,
                'requested' => $toTransfer->count(),
            ],
        ], 422);
    }

    // الطلاب اللي أصلاً بالشعبة الهدف — ما بينقلوا، بس لازم نذكرهم بالرد
    $alreadyThere = $students->where('section_id', $targetSection->id);

    return DB::transaction(function () use ($toTransfer, $alreadyThere, $targetSection, $students) {
        $updated = [];

        foreach ($toTransfer as $student) {
            $from = $student->section_id;

            $student->section_id = $targetSection->id;
            $student->save();

            $updated[] = [
                'student_id' => $student->id,
                'student_number' => $student->student_number,
                'from_section_id' => $from,
                'new_section_id' => $targetSection->id,
                'new_section_name' => $targetSection->name,
            ];
        }

        $movedCount = count($updated);
        $skippedCount = $alreadyThere->count();

        /*
         | الرسالة لازم تعكس اللي صار.
         | نقل طالب لشعبة هو فيها أصلاً = ما في نقل، فما بصير نقول "تم النقل".
         */
        if ($movedCount === 0) {
            $message = $skippedCount === 1
                ? 'الطالب موجود في هذه الشعبة مسبقاً، لم يتم نقل أحد'
                : "الطلاب ({$skippedCount}) موجودون في هذه الشعبة مسبقاً، لم يتم نقل أحد";
        } else {
            $message = "تم نقل {$movedCount} طالب إلى شعبة {$targetSection->name}";

            if ($skippedCount > 0) {
                $message .= "، و{$skippedCount} كانوا فيها مسبقاً";
            }
        }

        $currentCount = Student::where('section_id', $targetSection->id)->count();

        return response()->json([
            'success' => true,
            'message' => $message,
            'changed' => $movedCount > 0,
            'data' => [
                'transferred' => $updated,
                'transferred_count' => $movedCount,
                'already_there_count' => $skippedCount,
                'requested_count' => $students->count(),
                'target_section' => [
                    'id' => $targetSection->id,
                    'name' => $targetSection->name,
                    'capacity' => $targetSection->capacity,
                    'current_count' => $currentCount,
                    'available_slots' => max(0, $targetSection->capacity - $currentCount),
                ],
            ],
        ]);
    });
}
}
