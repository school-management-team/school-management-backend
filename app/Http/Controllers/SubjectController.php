<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\WeeklySchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
     public function index()
    {
        $subjects = Subject::with('stages')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => $subjects,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:subjects,name',
            'passing_grade' => 'required|numeric|min:0|max:100',
            'description' => 'nullable|string',
            'stage_ids' => 'required|array|min:1',
            'stage_ids.*' => 'required|exists:stages,id',
        ]);

        return DB::transaction(function () use ($validated) {
            $subject = Subject::create([
                'name' => $validated['name'],
                'passing_grade' => $validated['passing_grade'],
                'description' => $validated['description'] ?? null,
            ]);

            $subject->stages()->sync($validated['stage_ids']);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء المادة وربطها بالمراحل بنجاح',
                'data' => $subject->load('stages'),
            ], 201);
        });
    }

    public function update(Request $request, Subject $subject)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:subjects,name,' . $subject->id,
            'passing_grade' => 'sometimes|numeric|min:0|max:100',
            'description' => 'nullable|string',
            'stage_ids' => 'sometimes|array|min:1',
            'stage_ids.*' => 'required_with:stage_ids|exists:stages,id',
        ]);

        if (count($validated) === 0) {
            return $this->nothingToUpdate();
        }

        return DB::transaction(function () use ($validated, $subject) {
            $subject->fill([
                'name' => $validated['name'] ?? $subject->name,
                'passing_grade' => $validated['passing_grade'] ?? $subject->passing_grade,
                'description' => array_key_exists('description', $validated)
                    ? $validated['description']
                    : $subject->description,
            ]);

            $changed = array_keys($subject->getDirty());

            /*
             | المراحل علاقة منفصلة، فما بتظهر بـ isDirty. منقارن القائمة
             | المرسلة بالمحفوظة (مرتّبة، لأن الترتيب ما بيهم) حتى ما نقول
             | "تم التعديل" على مزامنة ما غيّرت شي.
             */
            $stagesChanged = false;

            if (isset($validated['stage_ids'])) {
                $current = $subject->stages()->pluck('stages.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $incoming = collect($validated['stage_ids'])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

                $stagesChanged = $current !== $incoming;
            }

            if (empty($changed) && !$stagesChanged) {
                return $this->noChangesMade($subject->load('stages'));
            }

            $subject->save();

            if ($stagesChanged) {
                $subject->stages()->sync($validated['stage_ids']);
                $changed[] = 'stages';
            }

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث المادة بنجاح',
                'changed' => true,
                'changed_fields' => $changed,
                'data' => $subject->fresh()->load('stages'),
            ]);
        });
    }

    public function destroy(Request $request, Subject $subject)
    {
        $teachersCount = Teacher::where('subject_id', $subject->id)->count();

        if ($teachersCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "لا يمكن حذف مادة هي تخصص لـ {$teachersCount} معلم",
                'data' => ['teachers_count' => $teachersCount],
            ], 422);
        }

        $impact = [
            'assignments' => TeacherAssignment::where('subject_id', $subject->id)->count(),
            'lessons' => WeeklySchedule::whereIn(
                'teacher_assignment_id',
                TeacherAssignment::where('subject_id', $subject->id)->select('id')
            )->count(),
            'grades' => Grade::where('subject_id', $subject->id)->count(),
            'submissions' => GradeSubmission::where('subject_id', $subject->id)->count(),
        ];

        $total = array_sum($impact);

        if ($total > 0 && !$request->boolean('force')) {
            return response()->json([
                'success' => false,
                'message' => "حذف المادة ({$subject->name}) سيحذف معها "
                    ."{$impact['assignments']} تكليف و{$impact['lessons']} حصة و"
                    ."{$impact['grades']} علامة و{$impact['submissions']} كشف علامات. "
                    .'أعد الطلب مع force=1 للتأكيد',
                'data' => $impact,
            ], 409);
        }

        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المادة بنجاح',
            'data' => array_merge(['deleted' => $impact], ['deleted_assignments' => $impact['assignments']]),
        ]);
    }
}
