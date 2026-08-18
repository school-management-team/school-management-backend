<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * صيغة التاريخ لازم تكون Y-m-d بالضبط.
 *
 * قاعدة 'date' العادية بتقبل صيغ متل 17-08-2026، بس MySQL ما بتفهمها
 * فبترجع صفر نتائج بدون أي خطأ — والمستخدم بيفكّر إنه ما في بيانات.
 */
class DateFormatTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->section = $this->makeSection('أ');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    public function test_the_correct_format_is_accepted(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/students?section_id='.$this->section->id.'&date=2026-08-17')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-08-17');
    }

    public function test_a_day_first_date_is_rejected_instead_of_returning_nothing(): void
    {
        // كانت بتمرق وبترجع صفر سجلات بصمت
        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/students?section_id='.$this->section->id.'&date=17-08-2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_slashes_are_rejected(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/students?section_id='.$this->section->id.'&date=2026/08/17')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_a_missing_date_defaults_to_today(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/students?section_id='.$this->section->id)
            ->assertOk()
            ->assertJsonPath('data.date', now()->toDateString());
    }

    public function test_other_date_endpoints_are_strict_too(): void
    {
        $urls = [
            '/api/supervisor/attendance/teachers?date=17-08-2026',
            '/api/supervisor/substitutions/absent-lessons?date=17-08-2026',
            '/api/supervisor/announcements/day-status?date=17-08-2026',
        ];

        foreach ($urls as $url) {
            $this->asSupervisor()->getJson($url)
                ->assertStatus(422)
                ->assertJsonValidationErrors('date');
        }
    }

    public function test_publishing_with_a_bad_date_is_rejected(): void
    {
        $this->asSupervisor()->postJson('/api/supervisor/announcements', [
            'title' => 'إعلان',
            'description' => 'وصف',
            'type' => 'academic',
            'date' => '17-08-2026',
        ])->assertStatus(422)->assertJsonValidationErrors('date');
    }

    // ==================== أيام العطل ====================

    public function test_a_weekend_sheet_is_flagged_as_a_non_school_day(): void
    {
        // 2026-08-15 يوم سبت
        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/students?section_id='.$this->section->id.'&date=2026-08-15')
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false)
            ->assertJsonPath('data.reason', 'weekend');
    }

    public function test_a_school_day_sheet_is_flagged_correctly(): void
    {
        // 2026-08-17 يوم اثنين
        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/students?section_id='.$this->section->id.'&date=2026-08-17')
            ->assertOk()
            ->assertJsonPath('data.is_school_day', true);
    }

    public function test_a_published_holiday_shows_in_the_sheet(): void
    {
        $this->asSupervisor()->postJson('/api/supervisor/announcements', [
            'title' => 'عيد الفطر',
            'description' => 'عطلة رسمية',
            'type' => 'holiday',
            'date' => '2026-08-17',
        ])->assertCreated();

        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/students?section_id='.$this->section->id.'&date=2026-08-17')
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false)
            ->assertJsonPath('data.reason', 'holiday')
            ->assertJsonPath('data.holiday.title', 'عيد الفطر');
    }

    public function test_the_teacher_sheet_is_flagged_too(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/attendance/teachers?date=2026-08-15')
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false)
            ->assertJsonPath('data.reason', 'weekend');
    }
}
