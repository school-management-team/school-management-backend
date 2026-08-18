<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class GuardianAttendanceTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private Guardian $guardian;
    private Student $child;
    private Student $otherChild;
    private $supervisor;

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

    private function actingAsGuardian(): self
    {
        return $this->actingAs($this->guardian->user, 'sanctum');
    }

    private function record(Student $student, string $date, string $status, array $extra = []): void
    {
        Attendance::create(array_merge([
            'student_id' => $student->id,
            'section_id' => $student->section_id,
            'supervisor_id' => $this->supervisor->id,
            'date' => $date,
            'status' => $status,
        ], $extra));
    }

    private function seedChildRecords(): void
    {
        $this->record($this->child, '2026-08-16', 'present');
        $this->record($this->child, '2026-08-17', 'absent');
        $this->record($this->child, '2026-08-18', 'late');
        $this->record($this->child, '2026-08-19', 'early_leave', ['left_at' => '11:00']);
        $this->record($this->child, '2026-08-20', 'excused', ['excuse' => 'مراجعة طبية']);
    }

    // ==================== الأولاد ====================

    public function test_guardian_sees_only_his_own_children(): void
    {
        $this->actingAsGuardian()
            ->getJson('/api/guardian/children')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.children.0.student_number', '10001');
    }

    // ==================== سجل الحضور ====================

    public function test_guardian_sees_the_full_attendance_log(): void
    {
        $this->seedChildRecords();

        $response = $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance")
            ->assertOk()
            ->assertJsonPath('data.student.student_number', '10001');

        $this->assertCount(5, $response->json('data.records.data'));
        // مرتّب من الأحدث للأقدم
        $this->assertSame('2026-08-20', $response->json('data.records.data.0.date'));
    }

    public function test_early_leave_shows_the_departure_time(): void
    {
        $this->seedChildRecords();

        $records = collect($this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance")
            ->json('data.records.data'));

        $earlyLeave = $records->firstWhere('status', 'early_leave');

        $this->assertSame('2026-08-19', $earlyLeave['date']);
        $this->assertSame('11:00:00', $earlyLeave['left_at']);
    }

    public function test_the_log_can_be_filtered_to_concerns_only(): void
    {
        $this->seedChildRecords();

        $records = $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance?only_concerns=1")
            ->assertOk()
            ->json('data.records.data');

        // كل شي ما عدا الحضور الكامل: غياب، تأخر، خروج مبكر، غياب بعذر
        $this->assertCount(4, $records);
        $this->assertNotContains('present', array_column($records, 'status'));
    }

    public function test_the_log_can_be_filtered_by_status(): void
    {
        $this->seedChildRecords();

        $records = $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance?status=absent")
            ->assertOk()
            ->json('data.records.data');

        $this->assertCount(1, $records);
        $this->assertSame('2026-08-17', $records[0]['date']);
    }

    public function test_the_log_can_be_filtered_by_date_range(): void
    {
        $this->seedChildRecords();

        $records = $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance?from=2026-08-17&to=2026-08-18")
            ->assertOk()
            ->json('data.records.data');

        $this->assertCount(2, $records);
    }

    // ==================== الملخّص ====================

    public function test_the_summary_counts_every_status(): void
    {
        $this->seedChildRecords();

        $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance/summary")
            ->assertOk()
            ->assertJsonPath('data.recorded_days', 5)
            ->assertJsonPath('data.counts.present', 1)
            ->assertJsonPath('data.counts.absent', 1)
            ->assertJsonPath('data.counts.late', 1)
            ->assertJsonPath('data.counts.early_leave', 1)
            ->assertJsonPath('data.counts.excused', 1)
            ->assertJsonPath('data.last_absence', '2026-08-17');
    }

    public function test_the_attendance_rate_treats_late_and_early_leave_as_attended(): void
    {
        $this->seedChildRecords();

        // حضر فعلياً 3 من 5 (حاضر + متأخر + خروج مبكر) = 60%
        $rate = $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance/summary")
            ->assertOk()
            ->json('data.attendance_rate');

        $this->assertEquals(60.0, $rate);
    }

    public function test_the_summary_is_null_when_nothing_is_recorded(): void
    {
        $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/attendance/summary")
            ->assertOk()
            ->assertJsonPath('data.recorded_days', 0)
            ->assertJsonPath('data.attendance_rate', null)
            ->assertJsonPath('data.last_absence', null);
    }

    // ==================== العزل والصلاحيات ====================

    public function test_a_guardian_cannot_read_another_students_record(): void
    {
        $this->record($this->otherChild, '2026-08-17', 'absent');

        $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->otherChild->id}/attendance")
            ->assertForbidden();
    }

    public function test_a_guardian_cannot_read_another_students_summary(): void
    {
        $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->otherChild->id}/attendance/summary")
            ->assertForbidden();
    }

    public function test_one_guardian_cannot_see_anothers_child(): void
    {
        $otherGuardian = Guardian::create([
            'relationship' => 'mother',
            'number_of_children' => 1,
            'user_id' => $this->makeUser('guardian', 'Other Parent')->id,
        ]);
        $otherGuardian->students()->attach($this->otherChild->id);

        // ولي الأمر التاني بيشوف ابنه هو بس
        $this->actingAs($otherGuardian->user, 'sanctum')
            ->getJson('/api/guardian/children')
            ->assertOk()
            ->assertJsonPath('data.children.0.student_number', '10002');

        // وما بيقدر يوصل لابن الأول
        $this->actingAs($otherGuardian->user, 'sanctum')
            ->getJson("/api/guardian/children/{$this->child->id}/attendance")
            ->assertForbidden();
    }

    public function test_other_roles_are_blocked(): void
    {
        $this->actingAs($this->supervisor->user, 'sanctum')
            ->getJson('/api/guardian/children')
            ->assertForbidden();
    }

    public function test_the_guardian_has_no_write_endpoints(): void
    {
        // القراءة فقط: أي محاولة كتابة لازم ترجع 405 أو 404، مش 2xx
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $response = $this->actingAsGuardian()
                ->{$method.'Json'}("/api/guardian/children/{$this->child->id}/attendance", ['status' => 'present']);

            $this->assertTrue(
                $response->status() >= 400,
                "الميثود {$method} رجّع {$response->status()} — المفروض تكون القراءة فقط"
            );
        }

        $this->assertSame(0, Attendance::count());
    }

    // ==================== تسجيل الخروج المبكر من طرف الموجّه ====================

    public function test_supervisor_can_record_an_early_leave(): void
    {
        $this->actingAs($this->supervisor->user, 'sanctum')
            ->postJson('/api/supervisor/attendance/students', [
                'section_id' => $this->child->section_id,
                'date' => '2026-08-16',
                'records' => [
                    ['student_id' => $this->child->id, 'status' => 'early_leave', 'left_at' => '11:30'],
                ],
            ])->assertCreated();

        $this->assertDatabaseHas('attendance', [
            'student_id' => $this->child->id,
            'status' => 'early_leave',
            'left_at' => '11:30:00',
        ]);
    }
}
