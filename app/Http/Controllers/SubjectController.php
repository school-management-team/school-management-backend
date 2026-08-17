<?php

namespace App\Http\Controllers;

use App\Models\Subject;
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

        return DB::transaction(function () use ($validated, $subject) {
            $subject->update([
                'name' => $validated['name'] ?? $subject->name,
                'passing_grade' => $validated['passing_grade'] ?? $subject->passing_grade,
                'description' => array_key_exists('description', $validated)
                    ? $validated['description']
                    : $subject->description,
            ]);

            if (isset($validated['stage_ids'])) {
                $subject->stages()->sync($validated['stage_ids']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Subject updated successfully',
                'data' => $subject->load('stages'),
            ]);
        });
    }

    public function destroy(Subject $subject)
    {
        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully',
        ]);
    }
}
