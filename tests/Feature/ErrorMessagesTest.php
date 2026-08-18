<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * الـ 404 إلها سببان مختلفان: رابط غلط، أو رقم غير موجود.
 * كانوا برسالة وحدة فما بتعرف وين المشكلة.
 */
class ErrorMessagesTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = $this->makeSupervisor();
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    public function test_an_unknown_path_says_the_path_is_wrong(): void
    {
        // شرطة سفلية بدل عادية
        $this->asSupervisor()
            ->deleteJson('/api/supervisor/fee_payments/15')
            ->assertNotFound()
            ->assertJsonPath('message', 'هذا المسار غير موجود — تأكد من الرابط')
            ->assertJsonPath('data.method', 'DELETE');
    }

    public function test_a_valid_path_with_a_missing_id_names_the_record(): void
    {
        $this->asSupervisor()
            ->deleteJson('/api/supervisor/fee-payments/9999')
            ->assertNotFound()
            ->assertJsonPath('message', 'لا يوجد دفعة بالرقم 9999')
            ->assertJsonPath('data.model', 'FeePayment')
            ->assertJsonPath('data.id', '9999');
    }

    public function test_each_model_gets_its_own_arabic_label(): void
    {
        $this->asSupervisor()->getJson('/api/supervisor/fees/9999')
            ->assertJsonPath('message', 'لا يوجد قسط بالرقم 9999');

        $this->asSupervisor()->getJson('/api/supervisor/schedule/section/9999')
            ->assertJsonPath('message', 'لا يوجد شعبة بالرقم 9999');

        $this->asSupervisor()->getJson('/api/supervisor/schedule/teacher/9999')
            ->assertJsonPath('message', 'لا يوجد معلم بالرقم 9999');
    }

    public function test_a_wrong_method_lists_the_supported_ones(): void
    {
        $section = $this->makeSection('أ');

        $this->asSupervisor()
            ->getJson("/api/supervisor/sections/{$section->id}")
            ->assertStatus(405)
            ->assertJsonPath('data.sent_method', 'GET')
            ->assertJsonPath('data.allowed_methods', 'PUT');
    }

    public function test_the_two_kinds_of_404_are_distinguishable(): void
    {
        $badPath = $this->asSupervisor()->getJson('/api/supervisor/lasha3rifo/1')->json('message');
        $badId = $this->asSupervisor()->getJson('/api/supervisor/fees/9999')->json('message');

        $this->assertNotSame($badPath, $badId, 'لازم كل سبب يعطي رسالة مختلفة');
    }
}
