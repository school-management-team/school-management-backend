<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * الحقول لازم تناسب الحالة: الغايب ما إلو وقت وصول، والحاضر ما إلو عذر.
 * وإعادة الإرسال بتحدّث مش بتسجّل من جديد — والرد لازم يوضّح.
 */
class AttendanceConsistencyTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private Teacher $teacher;
    private $section;
    private $student;
    private string $day = '2026-08-17';   // اثنين

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->teacher = $this->makeTeacher($this->makeSubject('رياضيات'), $this->makeStage());
        $this->section = $this->makeSection('أ');
        $this->student = $this->makeStudent($this->section, '10001');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function recordTeacher(array $record)
    {
        return $this->asSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->day,
            'records' => [array_merge(['teacher_id' => $this->teacher->id], $record)],
        ]);
    }

    private function recordStudent(array $record)
    {
        return $this->asSupervisor()->postJson('/api/supervisor/attendance/students', [
            'section_id' => $this->section->id,
            'date' => $this->day,
            'records' => [array_merge(['student_id' => $this->student->id], $record)],
        ]);
    }

    // ==================== تناسق الحقول ====================

    public function test_an_absent_teacher_cannot_have_a_check_in_time(): void
    {
        $this->recordTeacher(['status' => 'absent', 'check_in_time' => '01:27'])
            ->assertStatus(422)
            ->assertJsonPath('data.field', 'check_in_time')
            ->assertJsonPath('data.status', 'absent')
            ->assertJsonPath('data.index', 0);

        $this->assertSame(0, TeacherAttendance::count());
    }

    public function test_a_late_teacher_can_have_a_check_in_time(): void
    {
        $this->recordTeacher(['status' => 'late', 'check_in_time' => '08:20'])
            ->assertCreated();

        $this->assertSame('08:20:00', TeacherAttendance::first()->check_in_time);
    }

    public function test_a_present_teacher_cannot_have_an_excuse(): void
    {
        $this->recordTeacher(['status' => 'present', 'excuse' => 'مرض'])
            ->assertStatus(422)
            ->assertJsonPath('data.field', 'excuse');
    }

    public function test_a_present_student_cannot_have_a_leave_time(): void
    {
        $this->recordStudent(['status' => 'present', 'left_at' => '11:00'])
            ->assertStatus(422)
            ->assertJsonPath('data.field', 'left_at');
    }

    public function test_an_early_leave_student_can_have_a_leave_time(): void
    {
        $this->recordStudent(['status' => 'early_leave', 'left_at' => '11:00'])
            ->assertCreated();
    }

    public function test_the_rejection_points_at_the_offending_record(): void
    {
        $second = $this->makeTeacher($this->makeSubject('عربي'), $this->makeStage());

        $this->asSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->day,
            'records' => [
                ['teacher_id' => $this->teacher->id, 'status' => 'present'],
                ['teacher_id' => $second->id, 'status' => 'absent', 'check_in_time' => '09:00'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.index', 1);

        // ولا سجل انحفظ — كلها أو ولا وحدة
        $this->assertSame(0, TeacherAttendance::count());
    }

    // ==================== تسجيل جديد مقابل تحديث ====================

    public function test_the_first_submission_reports_a_new_record(): void
    {
        $this->recordTeacher(['status' => 'absent'])
            ->assertCreated()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.updated_count', 0);
    }

    public function test_resending_reports_an_update_not_a_new_record(): void
    {
        $this->recordTeacher(['status' => 'absent'])->assertCreated();

        $this->recordTeacher(['status' => 'absent'])
            ->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.updated_count', 1);

        // ولا سجل مكرر — في unique على (teacher_id, date)
        $this->assertSame(1, TeacherAttendance::count());
    }

    public function test_changing_the_status_updates_the_same_record(): void
    {
        $this->recordTeacher(['status' => 'absent'])->assertCreated();
        $this->recordTeacher(['status' => 'present'])->assertOk();

        $this->assertSame(1, TeacherAttendance::count());
        $this->assertSame('present', TeacherAttendance::first()->status);
    }

    public function test_student_attendance_reports_created_and_updated_too(): void
    {
        $this->recordStudent(['status' => 'present'])
            ->assertCreated()
            ->assertJsonPath('data.created_count', 1);

        $this->recordStudent(['status' => 'absent', 'excuse' => 'مرض'])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);
    }
}
