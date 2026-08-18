<?php

namespace Tests\Feature;

use App\Models\FeePayment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class StudentFeeTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private Guardian $guardian;
    private Student $child;
    private Student $otherChild;
    private string $year = '2026-2027';

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $section = $this->makeSection('A');

        $this->child = $this->makeStudent($section, '10001');
        $this->otherChild = $this->makeStudent($section, '10002');

        $this->guardian = Guardian::create([
            'relationship' => 'father',
            'number_of_children' => 1,
            'user_id' => $this->makeUser('guardian', 'Parent')->id,
        ]);

        $this->guardian->students()->attach($this->child->id);
    }

    private function asSupervisor(): self { return $this->actingAs($this->supervisor->user, 'sanctum'); }
    private function asGuardian(): self { return $this->actingAs($this->guardian->user, 'sanctum'); }

    private function createFee(Student $student = null, array $overrides = []): StudentFee
    {
        $this->asSupervisor()->postJson('/api/supervisor/fees', array_merge([
            'student_id' => ($student ?? $this->child)->id,
            'academic_year' => $this->year,
            'total_amount' => 1000000,
        ], $overrides))->assertCreated();

        return StudentFee::latest('id')->first();
    }

    private function pay(StudentFee $fee, $amount)
    {
        return $this->asSupervisor()
            ->postJson("/api/supervisor/fees/{$fee->id}/payments", ['amount' => $amount]);
    }

    // ==================== تسجيل القسط ====================

    public function test_supervisor_registers_an_annual_fee(): void
    {
        $this->createFee();

        $this->assertDatabaseHas('student_fees', [
            'student_id' => $this->child->id,
            'academic_year' => $this->year,
            'total_amount' => 1000000,
        ]);
    }

    public function test_the_same_year_cannot_be_registered_twice(): void
    {
        $this->createFee();

        $this->asSupervisor()->postJson('/api/supervisor/fees', [
            'student_id' => $this->child->id,
            'academic_year' => $this->year,
            'total_amount' => 900000,
        ])->assertStatus(422);

        $this->assertSame(1, StudentFee::count());
    }

    public function test_a_discount_larger_than_the_total_is_rejected(): void
    {
        $this->asSupervisor()->postJson('/api/supervisor/fees', [
            'student_id' => $this->child->id,
            'academic_year' => $this->year,
            'total_amount' => 100000,
            'discount' => 200000,
        ])->assertStatus(422);
    }

    public function test_a_malformed_academic_year_is_rejected(): void
    {
        $this->asSupervisor()->postJson('/api/supervisor/fees', [
            'student_id' => $this->child->id,
            'academic_year' => '2026',
            'total_amount' => 100000,
        ])->assertStatus(422)->assertJsonValidationErrors('academic_year');
    }

    // ==================== الحساب ====================

    public function test_the_discount_reduces_the_net_amount(): void
    {
        $fee = $this->createFee(overrides: ['total_amount' => 1000000, 'discount' => 150000]);

        $this->assertEquals(850000, $fee->net_amount);
        $this->assertEquals(850000, $fee->remaining_amount);
    }

    public function test_payments_reduce_the_remaining_balance(): void
    {
        $fee = $this->createFee();

        $this->pay($fee, 300000)->assertCreated();
        $this->pay($fee, 200000)->assertCreated();

        $fee->refresh()->load('payments');

        $this->assertEquals(500000, $fee->paid_amount);
        $this->assertEquals(500000, $fee->remaining_amount);
        $this->assertFalse($fee->is_settled);
    }

    public function test_a_fee_becomes_settled_when_fully_paid(): void
    {
        $fee = $this->createFee();

        $this->pay($fee, 1000000)->assertCreated();

        $fee->refresh()->load('payments');

        $this->assertEquals(0, $fee->remaining_amount);
        $this->assertTrue($fee->is_settled);
    }

    public function test_a_payment_cannot_exceed_the_remaining_balance(): void
    {
        $fee = $this->createFee();
        $this->pay($fee, 800000)->assertCreated();

        // المتبقي 200000، منجرّب ندفع 300000
        $this->pay($fee, 300000)
            ->assertStatus(422)
            ->assertJsonPath('data.remaining_amount', 200000);

        $this->assertSame(1, FeePayment::count());
    }

    public function test_deleting_a_payment_restores_the_balance(): void
    {
        $fee = $this->createFee();
        $this->pay($fee, 400000)->assertCreated();

        $payment = FeePayment::first();

        $this->asSupervisor()
            ->deleteJson("/api/supervisor/fee-payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.remaining_amount', 1000000);
    }

    public function test_the_net_amount_cannot_drop_below_what_was_paid(): void
    {
        $fee = $this->createFee();
        $this->pay($fee, 700000)->assertCreated();

        // منجرّب نخفّض الإجمالي تحت المدفوع
        $this->asSupervisor()
            ->putJson("/api/supervisor/fees/{$fee->id}", ['total_amount' => 500000])
            ->assertStatus(422);

        $this->assertEquals(1000000, $fee->fresh()->total_amount);
    }

    // ==================== واجهة ولي الأمر ====================

    public function test_guardian_sees_the_fee_breakdown(): void
    {
        $fee = $this->createFee(overrides: ['total_amount' => 1000000, 'discount' => 100000]);
        $this->pay($fee, 400000)->assertCreated();

        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/fees")
            ->assertOk()
            ->assertJsonPath('data.fees.0.total_amount', 1000000)
            ->assertJsonPath('data.fees.0.discount', 100000)
            ->assertJsonPath('data.fees.0.net_amount', 900000)
            ->assertJsonPath('data.fees.0.paid_amount', 400000)
            ->assertJsonPath('data.fees.0.remaining_amount', 500000)
            ->assertJsonPath('data.fees.0.is_settled', false);
    }

    public function test_guardian_sees_the_payment_history(): void
    {
        $fee = $this->createFee();
        $this->pay($fee, 300000)->assertCreated();
        $this->pay($fee, 200000)->assertCreated();

        $payments = $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/fees")
            ->assertOk()
            ->json('data.fees.0.payments');

        $this->assertCount(2, $payments);
        $this->assertArrayHasKey('receipt_number', $payments[0]);
    }

    public function test_totals_add_up_across_years(): void
    {
        $first = $this->createFee(overrides: ['academic_year' => '2025-2026', 'total_amount' => 800000]);
        $this->pay($first, 800000)->assertCreated();

        $second = $this->createFee(overrides: ['academic_year' => '2026-2027', 'total_amount' => 1000000]);
        $this->pay($second, 250000)->assertCreated();

        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/fees")
            ->assertOk()
            ->assertJsonPath('data.totals.net_amount', 1800000)
            ->assertJsonPath('data.totals.paid_amount', 1050000)
            ->assertJsonPath('data.totals.remaining_amount', 750000);
    }

    public function test_fees_can_be_filtered_by_year(): void
    {
        $this->createFee(overrides: ['academic_year' => '2025-2026']);
        $this->createFee(overrides: ['academic_year' => '2026-2027']);

        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/fees?academic_year=2026-2027")
            ->assertOk()
            ->assertJsonCount(1, 'data.fees')
            ->assertJsonPath('data.fees.0.academic_year', '2026-2027');
    }

    public function test_a_child_with_no_fee_returns_an_empty_list(): void
    {
        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/fees")
            ->assertOk()
            ->assertJsonCount(0, 'data.fees')
            ->assertJsonPath('data.totals.remaining_amount', 0);
    }

    // ==================== قائمة الأقساط وتفاصيلها (الموجّه) ====================

    public function test_supervisor_lists_all_fees(): void
    {
        $this->createFee($this->child);
        $this->createFee($this->otherChild);

        $this->asSupervisor()
            ->getJson('/api/supervisor/fees')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(2, 'data.fees');
    }

    public function test_the_list_can_show_unsettled_fees_only(): void
    {
        $paid = $this->createFee($this->child);
        $this->pay($paid, 1000000)->assertCreated();     // مسدّد بالكامل

        $this->createFee($this->otherChild);              // بدون أي دفعة

        $response = $this->asSupervisor()
            ->getJson('/api/supervisor/fees?unsettled=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame($this->otherChild->id, $response->json('data.fees.0.student.student_id'));
    }

    public function test_the_list_can_be_filtered_by_year_and_section(): void
    {
        $this->createFee($this->child, ['academic_year' => '2025-2026']);
        $this->createFee($this->child, ['academic_year' => '2026-2027']);

        $this->asSupervisor()
            ->getJson('/api/supervisor/fees?academic_year=2026-2027')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->asSupervisor()
            ->getJson('/api/supervisor/fees?section_id='.$this->child->section_id)
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_supervisor_opens_a_fee_with_its_payment_history(): void
    {
        $fee = $this->createFee();
        $this->pay($fee, 300000)->assertCreated();
        $this->pay($fee, 200000)->assertCreated();

        $data = $this->asSupervisor()
            ->getJson("/api/supervisor/fees/{$fee->id}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 500000)
            ->assertJsonPath('data.remaining_amount', 500000)
            ->json('data');

        $this->assertCount(2, $data['payments']);
        $this->assertArrayHasKey('recorded_by', $data['payments'][0]);
    }

    public function test_supervisor_updates_the_fee_amount_and_discount(): void
    {
        $fee = $this->createFee();

        $this->asSupervisor()
            ->putJson("/api/supervisor/fees/{$fee->id}", [
                'total_amount' => 1200000,
                'discount' => 200000,
                'note' => 'حسم أخوة',
            ])
            ->assertOk()
            ->assertJsonPath('data.net_amount', 1000000)
            ->assertJsonPath('data.remaining_amount', 1000000);

        $this->assertSame('حسم أخوة', $fee->fresh()->note);
    }

    public function test_updating_with_a_discount_above_the_total_is_rejected(): void
    {
        $fee = $this->createFee();

        $this->asSupervisor()
            ->putJson("/api/supervisor/fees/{$fee->id}", ['discount' => 2000000])
            ->assertStatus(422);
    }

    // ==================== العزل والصلاحيات ====================

    public function test_a_guardian_cannot_read_another_students_fees(): void
    {
        $this->createFee($this->otherChild);

        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->otherChild->id}/fees")
            ->assertForbidden();
    }

    public function test_a_guardian_cannot_register_a_fee(): void
    {
        $this->asGuardian()->postJson('/api/supervisor/fees', [
            'student_id' => $this->child->id,
            'academic_year' => $this->year,
            'total_amount' => 1,
        ])->assertForbidden();

        $this->assertSame(0, StudentFee::count());
    }

    public function test_a_guardian_cannot_record_a_payment(): void
    {
        $fee = $this->createFee();

        $this->asGuardian()
            ->postJson("/api/supervisor/fees/{$fee->id}/payments", ['amount' => 100])
            ->assertForbidden();

        $this->assertSame(0, FeePayment::count());
    }
}
