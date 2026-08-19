<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\Teacher;
use App\Notifications\ClassAnnouncement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/** نقاط قراءة الإشعارات: القائمة، التعليم كمقروء، العدّاد */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private Teacher $teacher;
    private Section $section;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $subject = $this->makeSubject('رياضيات');
        $this->teacher = $this->makeTeacher($subject, $this->makeStage(), 'أستاذ الرياضيات');
        $this->section = $this->makeSection('أ');
        $this->student = $this->makeStudent($this->section, '10001');
        $this->makeAssignment($this->teacher, $subject, $this->section);
    }

    private function asStudent(): self
    {
        return $this->actingAs($this->student->user, 'sanctum');
    }

    /** يرسل عدداً من الإشعارات للطالب */
    private function push(int $count = 1): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->student->user->notify(
                new ClassAnnouncement($this->teacher, $this->section, "عنوان {$i}", "نص {$i}")
            );
        }
    }

    public function test_an_empty_inbox_explains_itself(): void
    {
        $this->asStudent()->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('message', 'لا توجد إشعارات بعد')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_notifications_are_listed_with_the_unified_shape(): void
    {
        $this->push();

        $item = $this->asStudent()->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->json('data.notifications.0');

        foreach (['id', 'type', 'title', 'message', 'icon', 'priority', 'data', 'is_read', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $item, "الحقل {$key} ناقص");
        }

        $this->assertSame('class.announcement', $item['type']);
        $this->assertFalse($item['is_read']);
    }

    public function test_the_unread_count_endpoint_works(): void
    {
        $this->push(3);

        $this->asStudent()->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_a_single_notification_can_be_marked_read(): void
    {
        $this->push(2);
        $id = $this->student->user->notifications()->first()->id;

        $this->asStudent()->patchJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_marking_an_already_read_one_reports_no_change(): void
    {
        $this->push();
        $id = $this->student->user->notifications()->first()->id;

        $this->asStudent()->patchJson("/api/notifications/{$id}/read")->assertOk();

        $this->asStudent()->patchJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('message', 'هذا الإشعار مقروء مسبقاً');
    }

    public function test_all_can_be_marked_read_at_once(): void
    {
        $this->push(4);

        $this->asStudent()->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_count', 4)
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_marking_all_when_none_unread_reports_no_change(): void
    {
        $this->asStudent()->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('changed', false);
    }

    public function test_the_list_can_be_filtered_to_unread_only(): void
    {
        $this->push(3);
        $id = $this->student->user->notifications()->first()->id;
        $this->asStudent()->patchJson("/api/notifications/{$id}/read")->assertOk();

        $this->asStudent()->getJson('/api/notifications?only_unread=1')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_the_list_can_be_filtered_by_type(): void
    {
        $this->push(2);

        $this->asStudent()->getJson('/api/notifications?type=class.announcement')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->asStudent()->getJson('/api/notifications?type=meeting.scheduled')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_a_user_cannot_touch_another_users_notification(): void
    {
        $this->push();
        $id = $this->student->user->notifications()->first()->id;

        // معلم بيحاول يعلّم إشعار الطالب كمقروء
        $this->actingAs($this->teacher->user, 'sanctum')
            ->patchJson("/api/notifications/{$id}/read")
            ->assertNotFound();

        $this->assertNull($this->student->user->notifications()->first()->read_at);
    }

    public function test_a_notification_can_be_deleted(): void
    {
        $this->push(2);
        $id = $this->student->user->notifications()->first()->id;

        $this->asStudent()->deleteJson("/api/notifications/{$id}")->assertOk();

        $this->assertSame(1, $this->student->user->notifications()->count());
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}
