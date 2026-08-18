<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\Request;

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
            'sections.*.name' => 'required|string|max:255',
            'sections.*.capacity' => 'required|integer|min:1',
        ]);

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
            'name' => 'sometimes|string|max:255',
            'capacity' => 'sometimes|integer|min:1',
        ]);

        $section->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Section updated successfully',
            'data' => $section->fresh('schoolClass'),
        ]);
    }
   
}
