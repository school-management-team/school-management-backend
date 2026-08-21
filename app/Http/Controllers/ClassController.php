<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClassController extends Controller
{
    public function index()
    {
        $classes = SchoolClass::with('stage:id,name')
            ->orderBy('grade_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $classes,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'grade_order' => 'required|integer|min:1|max:12',
            'stage_id' => 'required|exists:stages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $exists = SchoolClass::where('stage_id', $request->stage_id)
            ->where('grade_order', $request->grade_order)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'يوجد صف بنفس الترتيب في هذه المرحلة',
            ], 422);
        }

        $class = SchoolClass::create([
            'name' => $request->name,
            'grade_order' => $request->grade_order,
            'stage_id' => $request->stage_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الصف بنجاح',
            'data' => $class->load('stage:id,name'),
        ], 201);
    }
public function update(Request $request, SchoolClass $schoolClass)
{
    $validator = Validator::make($request->all(), [
        'name' => 'sometimes|required|string|max:255',
        'grade_order' => 'sometimes|required|integer|min:1|max:12',
        'stage_id' => 'sometimes|required|exists:stages,id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors(),
        ], 422);
    }

    if (count($validator->validated()) === 0) {
        return $this->nothingToUpdate();
    }

    $stageId = $request->stage_id ?? $schoolClass->stage_id;
    $gradeOrder = $request->grade_order ?? $schoolClass->grade_order;

    $exists = SchoolClass::where('stage_id', $stageId)
        ->where('grade_order', $gradeOrder)
        ->where('id', '!=', $schoolClass->id)
        ->exists();

    if ($exists) {
        return response()->json([
            'success' => false,
            'message' => 'يوجد صف آخر بنفس الترتيب في هذه المرحلة',
        ], 422);
    }

    $schoolClass->fill($request->only(['name', 'grade_order', 'stage_id']));

    if (!$schoolClass->isDirty()) {
        return $this->noChangesMade($schoolClass->load('stage:id,name'));
    }

    $changed = array_keys($schoolClass->getDirty());
    $schoolClass->save();

    return response()->json([
        'success' => true,
        'message' => 'تم تحديث الصف بنجاح',
        'changed' => true,
        'changed_fields' => $changed,
        'data' => $schoolClass->fresh()->load('stage:id,name'),
    ]);
}
}
