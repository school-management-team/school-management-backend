<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Models\Teacher;
use App\Notifications\AcademicDropAlert;
use App\Notifications\ClassAnnouncement;
use App\Notifications\ParentMeetingScheduled;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private Teacher $teacher;
    private Section $section;
    private Student $studentA;
    private Student $studentB;
    private Guardian $guardian;
    private $math;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->math = $this->makeSubject('رياضيات');
        $this->teacher = $this->makeTeacher($this->math, $this->makeStage(), 'أستاذ الرياضيات');

        $this->section = $this->makeSection('أ');
        $this->studentA = $this->makeStudent($this->section, '10001');
        $this->studentB = $this->makeStudent($this->section, '10002');

        $this->makeAssignment($this->teacher, $this->math, $this->section);

        $this->guardian = Guardian::create([
            'relationship' => 'father',
            'number_of_children' => 1,
            'user_id' => $this->makeUser('guardian', 'ولي أمر')->id,
        ]);
        $this->guardian->students()->attach($this->studentA->id);
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher->user, 'sanctum');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    // ==================== إشعارات المعلم ====================

    public function test_a_class_announcement_reaches_every_student(): void
    {
        Notification::fake();

        $this->asTeacher()->postJson('/api/teacher/notifications/class-announcement', [
            'section_id' => $this->section->id,
            'title' => 'اختبار قصير',
            'body' => 'اختبار الغد في الوحدة الثالثة',
        ])->assertCreated()->assertJsonPath('data.recipients', 2);

        Notification::assertSentTo($this->studentA->user, ClassAnnouncement::class);
        Notification::assertSentTo($this->studentB->user, ClassAnnouncement::class);
    }

    public function test_a_teacher_cannot_notify_a_section_he_does_not_teach(): void
    {
        Notification::fake();
        $other = $this->makeSection('ب');

        $this->asTeacher()->postJson('/api/teacher/notifications/class-announcement', [
            'section_id' => $other->id,
            'title' => 'عنوان',
            'body' => 'نص',
        ])->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_the_announcement_is_stored_for_history(): void
    {
        $this->asTeacher()->postJson('/api/teacher/notifications/class-announcement', [
            'section_id' => $this->section->id,
            'title' => 'اختبار قصير',
            'body' => 'اختبار الغد',
        ])->assertCreated();

        $this->assertSame(1, $this->studentA->user->notifications()->count());

        $stored = $this->studentA->user->notifications()->first();
        $this->assertSame('class.announcement', $stored->data['type']);
        $this->assertSame('اختبار قصير', $stored->data['title']);
    }

    // ==================== إشعارات الموجّه ====================

    public function test_an_academic_drop_reaches_teachers_and_guardians(): void
    {
        Notification::fake();

        $this->asSupervisor()->postJson('/api/supervisor/notifications/academic-drop', [
            'student_id' => $this->studentA->id,
            'subject_id' => $this->math->id,
            'previous_value' => 85,
            'current_value' => 55,
            'note' => 'تراجع في اختبارين متتاليين',
        ])->assertCreated()
            ->assertJsonPath('data.teachers', 1)
            ->assertJsonPath('data.guardians', 1);

        Notification::assertSentTo($this->teacher->user, AcademicDropAlert::class);
        Notification::assertSentTo($this->guardian->user, AcademicDropAlert::class);
    }

    public function test_the_drop_alert_can_target_guardians_only(): void
    {
        Notification::fake();

        $this->asSupervisor()->postJson('/api/supervisor/notifications/academic-drop', [
            'student_id' => $this->studentA->id,
            'subject_id' => $this->math->id,
            'previous_value' => 85,
            'current_value' => 55,
            'notify' => ['guardians'],
        ])->assertCreated()->assertJsonPath('data.teachers', 0);

        Notification::assertNotSentTo($this->teacher->user, AcademicDropAlert::class);
        Notification::assertSentTo($this->guardian->user, AcademicDropAlert::class);
    }

    public function test_a_rise_is_not_a_drop(): void
    {
        Notification::fake();

        $this->asSupervisor()->postJson('/api/supervisor/notifications/academic-drop', [
            'student_id' => $this->studentA->id,
            'subject_id' => $this->math->id,
            'previous_value' => 55,
            'current_value' => 85,
        ])->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_a_parent_meeting_reaches_the_guardian(): void
    {
        Notification::fake();

        $this->asSupervisor()->postJson('/api/supervisor/notifications/parent-meeting', [
            'student_id' => $this->studentA->id,
            'meeting_date' => now()->addWeek()->toDateString(),
            'meeting_time' => '10:30',
            'location' => 'مكتب التوجيه',
        ])->assertCreated()->assertJsonPath('data.guardians', 1);

        Notification::assertSentTo($this->guardian->user, ParentMeetingScheduled::class);
    }

    public function test_a_meeting_in_the_past_is_rejected(): void
    {
        $this->asSupervisor()->postJson('/api/supervisor/notifications/parent-meeting', [
            'student_id' => $this->studentA->id,
            'meeting_date' => now()->subWeek()->toDateString(),
            'meeting_time' => '10:30',
        ])->assertStatus(422)->assertJsonValidationErrors('meeting_date');
    }

    public function test_a_student_without_a_guardian_reports_zero(): void
    {
        Notification::fake();

        $this->asSupervisor()->postJson('/api/supervisor/notifications/parent-meeting', [
            'student_id' => $this->studentB->id,
            'meeting_date' => now()->addWeek()->toDateString(),
            'meeting_time' => '10:30',
        ])->assertOk()
            ->assertJsonPath('data.recipients', 0)
            ->assertJsonPath('message', 'لا يوجد ولي أمر مرتبط بهذا الطالب');
    }

    public function test_a_teacher_cannot_send_supervisor_notifications(): void
    {
        $this->asTeacher()->postJson('/api/supervisor/notifications/academic-drop', [
            'student_id' => $this->studentA->id,
            'subject_id' => $this->math->id,
            'previous_value' => 85,
            'current_value' => 55,
        ])->assertForbidden();
    }
}
