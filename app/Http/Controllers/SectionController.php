<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'class_id' => 'sometimes|exists:classes,id',
        ]);

        $query = Section::with('schoolClass')->withCount('students');

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        $found = $query->orderBy('class_id')->orderBy('name')->get();

        $sections = [];

        foreach ($found as $section) {
            $sections[] = [
                'id' => $section->id,
                'name' => $section->name,
                'capacity' => $section->capacity,
                'students_count' => $section->students_count,
                'available_slots' => max(0, $section->capacity - $section->students_count),
                'class_id' => $section->class_id,
                'class_name' => $section->schoolClass ? $section->schoolClass->name : null,
            ];
        }

        if (count($sections) === 0) {
            $message = $request->filled('class_id')
                ? 'لا توجد شعب في هذا الصف بعد'
                : 'لا توجد شعب مسجّلة بعد';
        } else {
            $message = 'عدد الشعب: '.count($sections);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'sections' => $sections,
                'total' => count($sections),
            ],
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

        $classId = $validated['class_id'];
        $names = [];

        foreach ($validated['sections'] as $sectionData) {
            $name = trim($sectionData['name']);
            $key = mb_strtolower($name);

            if (in_array($key, $names)) {
                return response()->json([
                    'success' => false,
                    'message' => "الشعبة \"{$name}\" مكررة ضمن نفس الطلب",
                ], 422);
            }

            $names[] = $key;

            $existing = Section::where('class_id', $classId)->where('name', $name)->first();

            if (!$existing) {
                continue;
            }

            $studentsCount = $existing->students()->count();

            if ($sectionData['capacity'] < $studentsCount) {
                return response()->json([
                    'success' => false,
                    'message' => "لا يمكن جعل سعة الشعبة \"{$name}\" أقل من عدد طلابها الحالي ({$studentsCount})",
                    'data' => [
                        'section_name' => $existing->name,
                        'enrolled_students' => $studentsCount,
                        'requested_capacity' => $sectionData['capacity'],
                    ],
                ], 422);
            }
        }

        $created = [];
        $updated = [];

        foreach ($validated['sections'] as $sectionData) {
            $name = trim($sectionData['name']);
            $capacity = $sectionData['capacity'];

            $section = Section::where('class_id', $classId)->where('name', $name)->first();

            if (!$section) {
                $created[] = Section::create([
                    'class_id' => $classId,
                    'name' => $name,
                    'capacity' => $capacity,
                ]);

                continue;
            }

            $section->update(['capacity' => $capacity]);
            $updated[] = $section;
        }

        $received = count($validated['sections']);

        $message = 'استلمنا '.$received.' شعبة: أنشأنا '.count($created);

        if (count($updated) > 0) {
            $message .= ' وحدّثنا '.count($updated).' موجودة مسبقاً';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'received_count' => $received,
                'created' => $created,
                'updated' => $updated,
                'created_count' => count($created),
                'updated_count' => count($updated),
            ],
        ], count($created) > 0 ? 201 : 200);
    }

    public function update(Request $request, Section $section)
    {
        if ($request->filled('name')) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

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

        if (count($validated) === 0) {
            return $this->nothingToUpdate();
        }

        if (isset($validated['capacity'])) {
            $currentCount = $section->students()->count();

            if ($validated['capacity'] < $currentCount) {
                return response()->json([
                    'success' => false,
                    'message' => "لا يمكن جعل السعة أقل من عدد الطلاب الحالي ({$currentCount})",
                    'data' => [
                        'enrolled_students' => $currentCount,
                        'requested_capacity' => $validated['capacity'],
                    ],
                ], 422);
            }
        }

        $section->fill($validated);

        if (!$section->isDirty()) {
            return $this->noChangesMade($section->load('schoolClass'));
        }

        $changed = array_keys($section->getDirty());
        $section->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الشعبة بنجاح',
            'changed' => true,
            'changed_fields' => $changed,
            'data' => $section->fresh('schoolClass'),
        ]);
    }

}
