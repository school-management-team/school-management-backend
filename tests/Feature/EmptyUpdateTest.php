<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Section;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * كل حقول التعديل 'sometimes'، يعني الـ body الفاضي كان يمرق الفاليديشن
 * و update([]) ما بيعمل شي — بس الرد كان يقول "تم التعديل بنجاح".
 * هون منتأكد إن كل نقاط التعديل بترفض الطلب الفاضي.
 */
class EmptyUpdateTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $section;
    private $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->section = $this->makeSection('A');
        $this->subject = $this->makeSubject('رياضيات');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function assertRejectsEmptyBody(string $method, string $url): void
    {
        $this->asSupervisor()
            ->{$method.'Json'}($url, [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'لم تُرسل أي بيانات للتعديل');
    }

    public function test_empty_section_update_is_rejected(): void
    {
        $this->assertRejectsEmptyBody('put', "/api/supervisor/sections/{$this->section->id}");

        // ولا شي تغيّر
        $this->assertSame('A', $this->section->fresh()->name);
    }

    public function test_empty_subject_update_is_rejected(): void
    {
        $this->assertRejectsEmptyBody('put', "/api/supervisor/subjects/{$this->subject->id}");
    }

    public function test_empty_class_update_is_rejected(): void
    {
        $this->assertRejectsEmptyBody('put', "/api/supervisor/classes/{$this->section->class_id}");
    }

    public function test_empty_teacher_assignment_update_is_rejected(): void
    {
        $teacher = $this->makeTeacher($this->subject, $this->makeStage());
        $assignment = $this->makeAssignment($teacher, $this->subject, $this->section);

        $this->assertRejectsEmptyBody('put', "/api/supervisor/teacher-assignments/{$assignment->id}");
    }

    public function test_empty_schedule_update_is_rejected(): void
    {
        $teacher = $this->makeTeacher($this->subject, $this->makeStage());
        $assignment = $this->makeAssignment($teacher, $this->subject, $this->section);

        $lesson = WeeklySchedule::create([
            'teacher_id' => $teacher->id,
            'teacher_assignment_id' => $assignment->id,
            'day_of_week' => 'sunday',
            'period_number' => 1,
            'start_time' => '08:00:00',
            'end_time' => '08:45:00',
            'type' => 'class',
        ]);

        $this->assertRejectsEmptyBody('put', "/api/supervisor/schedule/{$lesson->id}");
    }

    public function test_empty_fee_update_is_rejected(): void
    {
        $student = $this->makeStudent($this->section, '10001');

        $fee = StudentFee::create([
            'student_id' => $student->id,
            'academic_year' => '2026-2027',
            'total_amount' => 1000000,
        ]);

        $this->assertRejectsEmptyBody('put', "/api/supervisor/fees/{$fee->id}");
    }

    public function test_empty_announcement_update_is_rejected(): void
    {
        $announcement = Announcement::create([
            'supervisor_id' => $this->supervisor->id,
            'title' => 'إعلان',
            'description' => 'وصف',
            'type' => 'academic',
            'date' => '2026-08-16',
        ]);

        $this->asSupervisor()
            ->postJson("/api/supervisor/announcements/{$announcement->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لم تُرسل أي بيانات للتعديل');

        $this->assertSame('إعلان', $announcement->fresh()->title);
    }

    // ==================== والتعديل الحقيقي لازم يضل شغّال ====================

    public function test_a_real_update_still_works(): void
    {
        $this->asSupervisor()
            ->putJson("/api/supervisor/sections/{$this->section->id}", ['capacity' => 44])
            ->assertOk();

        $this->assertSame(44, $this->section->fresh()->capacity);
    }

    public function test_updating_one_field_only_is_enough(): void
    {
        $teacher = $this->makeTeacher($this->subject, $this->makeStage());
        $assignment = $this->makeAssignment($teacher, $this->subject, $this->section);
        $otherSection = $this->makeSection('B');

        $this->asSupervisor()
            ->putJson("/api/supervisor/teacher-assignments/{$assignment->id}", [
                'section_id' => $otherSection->id,
            ])
            ->assertOk();

        $this->assertSame($otherSection->id, $assignment->fresh()->section_id);
    }
}
