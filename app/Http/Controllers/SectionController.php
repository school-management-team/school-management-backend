<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
 public function index()
    {
        $sections = Section::with('schoolClass')->get();

        return response()->json([
            'success' => true,
            'data' => $sections,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'sections' => 'required|array|min:1',
            'sections.*.name' => 'required|string|max:255|distinct',
            'sections.*.capacity' => 'required|integer|min:1',
        ]);

        foreach ($validated['sections'] as $sectionData) {
            $existing = Section::where('class_id', $validated['class_id'])
                ->where('name', $sectionData['name'])
                ->first();

            if ($existing) {
                $currentCount = $existing->students()->count();

                if ($sectionData['capacity'] < $currentCount) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Capacity cannot be less than the number of enrolled students',
                        'data' => [
                            'section_name' => $existing->name,
                            'enrolled_students' => $currentCount,
                            'requested_capacity' => $sectionData['capacity'],
                        ],
                    ], 422);
                }
            }
        }

        $created = [];

        foreach ($validated['sections'] as $sectionData) {
            $created[] = Section::updateOrCreate(
                [
                    'class_id' => $validated['class_id'],
                    'name' => $sectionData['name'],
                ],
                [
                    'capacity' => $sectionData['capacity'],
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Sections created successfully',
            'data' => $created,
        ], 201);
    }

    public function update(Request $request, Section $section)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('sections')
                    ->where(fn ($query) => $query->where('class_id', $section->class_id))
                    ->ignore($section->id),
            ],
            'capacity' => 'sometimes|integer|min:1',
        ]);

        if (isset($validated['capacity'])) {
            $currentCount = $section->students()->count();

            if ($validated['capacity'] < $currentCount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Capacity cannot be less than the number of enrolled students',
                    'data' => [
                        'enrolled_students' => $currentCount,
                        'requested_capacity' => $validated['capacity'],
                    ],
                ], 422);
            }
        }

        $section->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Section updated successfully',
            'data' => $section->fresh('schoolClass'),
        ]);
    }

}
