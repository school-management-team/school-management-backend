<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\ReportCardService;
use Illuminate\Http\Request;

class StudentReportController extends Controller
{
    protected $reportCardService;

    public function __construct(ReportCardService $reportCardService)
    {
        $this->reportCardService = $reportCardService;
    }

    /**
     * البحث عن طالب حتى يفتح الموجّه كشفه.
     * GET /supervisor/students/search?q=أحمد&section_id=3
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'sometimes|string|max:100',
            'section_id' => 'sometimes|exists:sections,id',
            'class_id' => 'sometimes|exists:classes,id',
        ]);

        $query = Student::with('user:id,user_name', 'section.schoolClass');

        if ($request->filled('section_id')) {
            $query->where('section_id', $request->section_id);
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        // البحث بالرقم أو بالاسم
        if ($request->filled('q')) {
            $term = $request->q;

            $query->where(function ($search) use ($term) {
                $search->where('student_number', 'like', '%'.$term.'%')
                    ->orWhereHas('user', function ($user) use ($term) {
                        $user->where('user_name', 'like', '%'.$term.'%');
                    });
            });
        }

        $found = $query->orderBy('student_number')->limit(50)->get();

        $students = [];

        foreach ($found as $student) {
            $className = null;

            if ($student->section && $student->section->schoolClass) {
                $className = $student->section->schoolClass->name;
            }

            $students[] = [
                'student_id' => $student->id,
                'student_number' => $student->student_number,
                'student_name' => $student->user ? $student->user->user_name : null,
                'class_name' => $className,
                'section_name' => $student->section ? $student->section->name : null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => ['students' => $students, 'total' => count($students)],
        ]);
    }

    /**
     * كشف علامات طالب. بدون semester بيرجّع الفصلين.
     * GET /supervisor/students/{student}/report-card?semester=1
     */
    public function reportCard(Request $request, Student $student)
    {
        $request->validate([
            'semester' => 'sometimes|integer|in:'.implode(',', config('school.semesters')),
        ]);

        if (!$student->section_id) {
            return response()->json([
                'success' => false,
                'message' => 'الطالب غير مسجّل في أي شعبة، لا يمكن إصدار كشف علاماته',
            ], 422);
        }

        // الموجّه بيشوف كل القيم حتى غير المعتمدة، عكس ولي الأمر
        if ($request->filled('semester')) {
            $semesters = [$this->reportCardService->forStudent($student, (int) $request->semester)];
        } else {
            $semesters = $this->reportCardService->forStudentAllSemesters($student);
        }

        $components = [];

        foreach (config('school.grade_components') as $type => $component) {
            $components[] = [
                'type' => $type,
                'label' => $component['label'],
                'weight' => $component['weight'],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $this->reportCardService->studentHeader($student),
                'grade_components' => $components,
                'semesters' => $semesters,
            ],
        ]);
    }
}
