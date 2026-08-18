<?php

namespace Tests\Feature;

use App\Models\StudentFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * السنة الدراسية لازم تكون سنتين متتاليتين (2026-2027).
 * الرفض لازم يقول الصيغة المطلوبة، مش "format is invalid" بس.
 */
class AcademicYearTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->student = $this->makeStudent($this->makeSection('أ'), '10001');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    public function test_a_bare_year_is_rejected_with_a_helpful_message(): void
    {
        $response = $this->asSupervisor()
            ->getJson('/api/supervisor/fees?academic_year=2026')
            ->assertStatus(422);

        // الرسالة لازم تعطي مثال على الصيغة
        $this->assertStringContainsString('2026-2027', $response->json('message'));
    }

    public function test_non_consecutive_years_are_rejected_with_a_suggestion(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/fees?academic_year=2026-2029')
            ->assertStatus(422)
            ->assertJsonPath('data.sent', '2026-2029')
            ->assertJsonPath('data.suggested', '2026-2027');
    }

    public function test_a_valid_year_filters_correctly(): void
    {
        StudentFee::create([
            'student_id' => $this->student->id,
            'academic_year' => '2026-2027',
            'total_amount' => 1000000,
        ]);

        $this->asSupervisor()
            ->getJson('/api/supervisor/fees?academic_year=2026-2027')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        // سنة تانية لازم ترجّع صفر — مش نفس النتيجة
        $this->asSupervisor()
            ->getJson('/api/supervisor/fees?academic_year=2029-2030')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_registering_a_fee_with_a_bad_year_is_rejected(): void
    {
        $this->asSupervisor()->postJson('/api/supervisor/fees', [
            'student_id' => $this->student->id,
            'academic_year' => '2026-2030',
            'total_amount' => 500000,
        ])->assertStatus(422)->assertJsonPath('data.suggested', '2026-2027');

        $this->assertSame(0, StudentFee::count());
    }

    public function test_a_backwards_year_range_is_rejected(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/fees?academic_year=2027-2026')
            ->assertStatus(422);
    }
}
