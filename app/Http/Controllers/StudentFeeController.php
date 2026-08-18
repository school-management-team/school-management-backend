<?php

namespace App\Http\Controllers;

use App\Models\FeePayment;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentFeeController extends Controller
{
    // صيغة السنة الدراسية: 2026-2027
    const YEAR_FORMAT = 'regex:/^\d{4}-\d{4}$/';

    // رسالة صريحة بدل "format is invalid" اللي ما بتقول شو الصيغة
    const YEAR_MESSAGE = ['academic_year.regex' => 'صيغة السنة الدراسية يجب أن تكون سنتين متتاليتين مثل 2026-2027'];

    /** قائمة الأقساط مع حالة السداد */
    public function index(Request $request)
    {
        $request->validate([
            'academic_year' => 'sometimes|string|'.self::YEAR_FORMAT,
            'section_id' => 'sometimes|exists:sections,id',
            'unsettled' => 'sometimes|boolean',
        ], self::YEAR_MESSAGE);

        if ($request->filled('academic_year')) {
            $yearError = $this->checkAcademicYear($request->academic_year);

            if ($yearError) {
                return $yearError;
            }
        }

        $query = StudentFee::with('student.user:id,user_name', 'student.section.schoolClass', 'payments');

        if ($request->filled('academic_year')) {
            $query->forYear($request->academic_year);
        }

        if ($request->filled('section_id')) {
            $sectionId = $request->section_id;

            $query->whereHas('student', function ($student) use ($sectionId) {
                $student->where('section_id', $sectionId);
            });
        }

        $found = $query->orderByDesc('academic_year')->get();

        $rows = [];

        foreach ($found as $fee) {
            // المتبقي مشتق مش عمود بالجدول، فمنفلتر عليه بعد الحساب
            if ($request->boolean('unsettled') && $fee->is_settled) {
                continue;
            }

            $rows[] = $this->present($fee);
        }

        return response()->json([
            'success' => true,
            'data' => ['fees' => $rows, 'total' => count($rows)],
        ]);
    }

    /** تسجيل قسط سنوي لطالب */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'academic_year' => 'required|string|'.self::YEAR_FORMAT,
            'total_amount' => 'required|numeric|min:0',
            'discount' => 'sometimes|numeric|min:0',
            'note' => 'nullable|string|max:255',
        ], self::YEAR_MESSAGE);

        $yearError = $this->checkAcademicYear($validated['academic_year']);

        if ($yearError) {
            return $yearError;
        }

        if (($validated['discount'] ?? 0) > $validated['total_amount']) {
            return response()->json([
                'success' => false,
                'message' => 'الحسم لا يمكن أن يتجاوز إجمالي القسط',
            ], 422);
        }

        $exists = StudentFee::where('student_id', $validated['student_id'])
            ->forYear($validated['academic_year'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'يوجد قسط مسجّل لهذا الطالب في هذه السنة الدراسية',
            ], 422);
        }

        $validated['created_by'] = $request->user()->id;

        $fee = StudentFee::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل القسط السنوي بنجاح',
            'data' => $this->present($fee->load('student.user:id,user_name', 'payments')),
        ], 201);
    }

    public function show(StudentFee $fee)
    {
        $fee->load(['student.user:id,user_name', 'student.section.schoolClass', 'payments.recorder:id,user_name']);

        return response()->json([
            'success' => true,
            'data' => $this->present($fee, withPayments: true),
        ]);
    }

    public function update(Request $request, StudentFee $fee)
    {
        $validated = $request->validate([
            'total_amount' => 'sometimes|numeric|min:0',
            'discount' => 'sometimes|numeric|min:0',
            'note' => 'nullable|string|max:255',
        ]);

        if (count($validated) === 0) {
            return $this->nothingToUpdate();
        }

        $total = $validated['total_amount'] ?? (float) $fee->total_amount;
        $discount = $validated['discount'] ?? (float) $fee->discount;

        if ($discount > $total) {
            return response()->json([
                'success' => false,
                'message' => 'الحسم لا يمكن أن يتجاوز إجمالي القسط',
            ], 422);
        }

        // ما بينفع نخفّض المستحق تحت المدفوع فعلاً
        if (round($total - $discount, 2) < $fee->paid_amount) {
            return response()->json([
                'success' => false,
                'message' => 'المبلغ المستحق لا يمكن أن يقل عن المبلغ المسدّد فعلاً ('.$fee->paid_amount.')',
            ], 422);
        }

        $fee->fill($validated);

        if (!$fee->isDirty()) {
            return $this->noChangesMade($this->present($fee->load('payments')));
        }

        $fee->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث القسط بنجاح',
            'data' => $this->present($fee->fresh('payments')),
        ]);
    }

    /** تسجيل دفعة على القسط */
    public function storePayment(Request $request, StudentFee $fee)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'paid_at' => 'sometimes|date_format:Y-m-d',
            'method' => 'sometimes|in:cash,bank_transfer,cheque,other',
            'receipt_number' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $fee, $validated) {
            // قفل الصف حتى ما تمرق دفعتين متوازيتين فوق المتبقي
            $locked = StudentFee::whereKey($fee->id)->lockForUpdate()->first();
            $remaining = $locked->remaining_amount;

            if ($validated['amount'] > $remaining) {
                return response()->json([
                    'success' => false,
                    'message' => 'الدفعة تتجاوز المبلغ المتبقي',
                    'data' => ['remaining_amount' => $remaining, 'attempted' => (float) $validated['amount']],
                ], 422);
            }

            $validated['student_fee_id'] = $locked->id;
            $validated['paid_at'] = $validated['paid_at'] ?? now()->toDateString();
            $validated['recorded_by'] = $request->user()->id;

            $payment = FeePayment::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الدفعة بنجاح',
                'data' => [
                    'payment' => $payment,
                    'fee' => $this->present($locked->fresh('payments')),
                ],
            ], 201);
        });
    }

    public function destroyPayment(FeePayment $payment)
    {
        $fee = $payment->studentFee;
        $payment->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الدفعة',
            'data' => $this->present($fee->fresh('payments')),
        ]);
    }

    // ==================== أدوات داخلية ====================

    /**
     * الصيغة regex بتتأكد من الشكل بس (2026-2029 بتمرق منها).
     * هون منتأكد إنهن سنتين متتاليتين فعلاً.
     */
    private function checkAcademicYear(string $year)
    {
        $parts = explode('-', $year);
        $start = (int) $parts[0];
        $end = (int) $parts[1];

        if ($end === $start + 1) {
            return null;
        }

        $suggested = $start.'-'.($start + 1);

        return response()->json([
            'success' => false,
            'message' => "السنة الدراسية يجب أن تكون سنتين متتاليتين. هل تقصد {$suggested}؟",
            'data' => ['sent' => $year, 'suggested' => $suggested],
        ], 422);
    }

    private function present(StudentFee $fee, bool $withPayments = false): array
    {
        $student = $fee->student;
        $section = $student ? $student->section : null;

        $data = [
            'fee_id' => $fee->id,
            'academic_year' => $fee->academic_year,
            'student' => [
                'student_id' => $fee->student_id,
                'student_number' => $student ? $student->student_number : null,
                'student_name' => $student && $student->user ? $student->user->user_name : null,
                'class_name' => $section && $section->schoolClass ? $section->schoolClass->name : null,
                'section_name' => $section ? $section->name : null,
            ],
            'total_amount' => (float) $fee->total_amount,
            'discount' => (float) $fee->discount,
            'net_amount' => $fee->net_amount,
            'paid_amount' => $fee->paid_amount,
            'remaining_amount' => $fee->remaining_amount,
            'is_settled' => $fee->is_settled,
            'note' => $fee->note,
        ];

        if ($withPayments) {
            $payments = [];

            foreach ($fee->payments->sortByDesc('paid_at') as $payment) {
                $payments[] = [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'method' => $payment->method,
                    'receipt_number' => $payment->receipt_number,
                    'recorded_by' => $payment->recorder ? $payment->recorder->user_name : null,
                ];
            }

            $data['payments'] = $payments;
        }

        return $data;
    }
}
