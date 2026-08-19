<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
     public function index()
    {
        $subjects = Subject::with('stages')->get();

        return response()->json([
            'success' => true,
            'data' => $subjects,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:subjects,name',
            'passing_grade' => 'required|numeric|min:0',
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
                'message' => 'Subject created and linked to stages successfully',
                'data' => $subject->load('stages'),
            ], 201);
        });
    }

    public function update(Request $request, Subject $subject)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:subjects,name,' . $subject->id,
            'passing_grade' => 'sometimes|numeric|min:0',
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

    public function destroy(Subject $subject)
    {
        $teachersCount = Teacher::where('subject_id', $subject->id)->count();

        if ($teachersCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a subject used as a teacher specialization',
                'data' => ['teachers_count' => $teachersCount],
            ], 422);
        }

        $assignmentsCount = TeacherAssignment::where('subject_id', $subject->id)->count();

        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully',
            'data' => ['deleted_assignments' => $assignmentsCount],
        ]);
    }
}
