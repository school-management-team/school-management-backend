<?php

namespace Database\Seeders;

use App\Models\FeePayment;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Database\Seeder;

class StudentFeeSeeder extends Seeder
{

    public function run(): void
    {
        $students = Student::whereNotNull('section_id')->orderBy('id')->get();

        if ($students->isEmpty()) {
            $this->command?->warn('لا يوجد طلاب موزّعين على شعب. شغّل StudentSectionSeeder أولاً.');
            return;
        }

        $year = '2026-2027';
        $recorder = User::where('role', 'supervisor')->first();

        FeePayment::query()->delete();
        StudentFee::query()->delete();

        foreach ($students as $index => $student) {
            $fee = StudentFee::create([
                'student_id' => $student->id,
                'academic_year' => $year,
                'total_amount' => 1200000,
                'discount' => $index % 4 === 0 ? 100000 : 0,
                'note' => $index % 4 === 0 ? 'حسم أخوة' : null,
                'created_by' => $recorder?->id,
            ]);

            
            $case = $index % 4;

            if ($case === 0) {
                $payments = [$fee->net_amount];
            } elseif ($case === 1) {
                $payments = [400000];
            } elseif ($case === 2) {
                $payments = [300000, 250000];
            } else {
                $payments = [];
            }

            foreach ($payments as $number => $amount) {
                FeePayment::create([
                    'student_fee_id' => $fee->id,
                    'amount' => $amount,
                    'paid_at' => now()->subMonths(count($payments) - $number)->toDateString(),
                    'method' => $number === 0 ? 'cash' : 'bank_transfer',
                    'receipt_number' => 'R-'.$fee->id.'-'.($number + 1),
                    'recorded_by' => $recorder?->id,
                ]);
            }
        }

        $fees = StudentFee::with('payments')->get();

        $this->command?->info(
            'أقساط: '.$fees->count().
            ' | مسدّدة بالكامل: '.$fees->where('is_settled', true)->count().
            ' | متبقٍ إجمالي: '.number_format($fees->sum('remaining_amount'))
        );
    }
}
