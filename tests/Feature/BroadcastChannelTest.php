<?php

namespace Tests\Feature;

use App\Models\Guardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * صلاحيات القنوات — من يقدر يشترك بأي قناة.
 * بدون هالفحوصات، أي مستخدم بيقدر يسمع إشعارات غيره.
 */
class BroadcastChannelTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    /** ينفّذ إغلاق التفويض مباشرة كما يفعله /broadcasting/auth */
    private function authorize(string $channel, $user, ...$params): bool
    {
        $channels = Broadcast::getChannels();
        $callback = null;

        foreach ($channels as $pattern => $handler) {
            if ($pattern === $channel) {
                $callback = $handler;
                break;
            }
        }

        $this->assertNotNull($callback, "القناة {$channel} غير معرّفة");

        return (bool) $callback($user, ...$params);
    }

    public function test_a_user_can_only_join_his_own_channel(): void
    {
        $me = $this->makeUser('student', 'أنا');
        $other = $this->makeUser('student', 'غيري');

        $this->assertTrue($this->authorize('user.{id}', $me, $me->id));
        $this->assertFalse($this->authorize('user.{id}', $me, $other->id));
    }

    public function test_a_suspended_user_is_rejected(): void
    {
        $user = $this->makeUser('student', 'موقوف');
        $user->update(['status' => 'pending']);

        $this->assertFalse($this->authorize('user.{id}', $user->fresh(), $user->id));
    }

    public function test_a_student_joins_only_his_own_section(): void
    {
        $sectionA = $this->makeSection('أ');
        $sectionB = $this->makeSection('ب');
        $student = $this->makeStudent($sectionA, '10001');

        $this->assertTrue($this->authorize('section.{sectionId}', $student->user, $sectionA->id));
        $this->assertFalse($this->authorize('section.{sectionId}', $student->user, $sectionB->id));
    }

    public function test_a_teacher_joins_only_sections_he_teaches(): void
    {
        $subject = $this->makeSubject('رياضيات');
        $teacher = $this->makeTeacher($subject, $this->makeStage());
        $mine = $this->makeSection('أ');
        $notMine = $this->makeSection('ب');

        $this->makeAssignment($teacher, $subject, $mine);

        $this->assertTrue($this->authorize('section.{sectionId}', $teacher->user, $mine->id));
        $this->assertFalse($this->authorize('section.{sectionId}', $teacher->user, $notMine->id));
    }

    public function test_a_guardian_joins_only_his_childs_section(): void
    {
        $mine = $this->makeSection('أ');
        $notMine = $this->makeSection('ب');
        $child = $this->makeStudent($mine, '10001');

        $guardian = Guardian::create([
            'relationship' => 'father',
            'number_of_children' => 1,
            'user_id' => $this->makeUser('guardian', 'ولي أمر')->id,
        ]);
        $guardian->students()->attach($child->id);

        $this->assertTrue($this->authorize('section.{sectionId}', $guardian->user, $mine->id));
        $this->assertFalse($this->authorize('section.{sectionId}', $guardian->user, $notMine->id));
    }

    public function test_a_supervisor_can_join_any_section(): void
    {
        $supervisor = $this->makeSupervisor();
        $section = $this->makeSection('أ');

        $this->assertTrue($this->authorize('section.{sectionId}', $supervisor->user, $section->id));
    }

    public function test_the_role_channel_matches_the_role(): void
    {
        $teacher = $this->makeUser('teacher', 'معلم');

        $this->assertTrue($this->authorize('role.{role}', $teacher, 'teacher'));
        $this->assertFalse($this->authorize('role.{role}', $teacher, 'supervisor'));
    }

    public function test_the_auth_endpoint_requires_a_token(): void
    {
        $this->postJson('/api/broadcasting/auth', ['channel_name' => 'private-user.1'])
            ->assertUnauthorized();
    }
}
