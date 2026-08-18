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
                'message' => 'A class with the same order already exists in this stage',
            ], 422);
        }

        $class = SchoolClass::create([
            'name' => $request->name,
            'grade_order' => $request->grade_order,
            'stage_id' => $request->stage_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Class created successfully',
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

    $stageId = $request->stage_id ?? $schoolClass->stage_id;
    $gradeOrder = $request->grade_order ?? $schoolClass->grade_order;

    $exists = SchoolClass::where('stage_id', $stageId)
        ->where('grade_order', $gradeOrder)
        ->where('id', '!=', $schoolClass->id)
        ->exists();

    if ($exists) {
        return response()->json([
            'success' => false,
            'message' => 'Another class with the same order already exists in this stage',
        ], 422);
    }

    $schoolClass->update($request->only(['name', 'grade_order', 'stage_id']));

    return response()->json([
        'success' => true,
        'message' => 'Class updated successfully',
        'data' => $schoolClass->fresh()->load('stage:id,name'),
    ]);
}
}
