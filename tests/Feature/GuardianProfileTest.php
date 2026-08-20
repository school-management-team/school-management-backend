<?php

namespace Tests\Feature;

use App\Models\Guardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class GuardianProfileTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = Guardian::create([
            'relationship' => 'father',
            'number_of_children' => 2,
            'user_id' => $this->makeUser('guardian', 'Parent')->id,
        ]);
    }

    private function asGuardian(): self
    {
        return $this->actingAs($this->guardian->user, 'sanctum');
    }

    // ==================== العرض ====================

    public function test_the_profile_returns_the_guardian_own_info(): void
    {
        $data = $this->asGuardian()
            ->getJson('/api/guardian/profile')
            ->assertOk()
            ->assertJsonPath('data.role', 'guardian')
            ->json('data');

        $this->assertSame($this->guardian->user->user_name, $data['user_name']);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('phone', $data);

        $this->assertSame('father', $data['profile']['relationship']);
        $this->assertSame(2, $data['profile']['number_of_children']);
    }

    public function test_the_relationship_has_an_arabic_label(): void
    {
        $this->asGuardian()
            ->getJson('/api/guardian/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.relationship', 'father')
            ->assertJsonPath('data.profile.relationship_label', 'الأب');
    }

    public function test_the_profile_does_not_carry_the_children_list(): void
    {
        // قائمة الأولاد إلها نقطتها الخاصة — البروفايل لولي الأمر وبس
        $profile = $this->asGuardian()
            ->getJson('/api/guardian/profile')
            ->assertOk()
            ->json('data.profile');

        $this->assertArrayNotHasKey('students', $profile);
    }

    public function test_the_profile_shows_the_join_date(): void
    {
        $this->asGuardian()
            ->getJson('/api/guardian/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.joined_at', $this->guardian->created_at->toDateString());
    }

    public function test_a_non_guardian_cannot_reach_the_guardian_profile(): void
    {
        $supervisor = $this->makeSupervisor();

        $this->actingAs($supervisor->user, 'sanctum')
            ->getJson('/api/guardian/profile')
            ->assertForbidden();
    }

    // ==================== التعديل ====================

    public function test_updating_touches_both_tables_in_one_request(): void
    {
        $this->asGuardian()
            ->putJson('/api/guardian/profile', [
                'user_name' => 'Abu Ahmad',
                'relationship' => 'mother',
            ])
            ->assertOk()
            ->assertJsonPath('changed', true);

        $this->assertSame('Abu Ahmad', $this->guardian->user->fresh()->user_name);
        $this->assertSame('mother', $this->guardian->fresh()->relationship);
    }

    public function test_the_response_names_the_fields_that_changed(): void
    {
        $changed = $this->asGuardian()
            ->putJson('/api/guardian/profile', [
                'number_of_children' => 4,
                'gender' => 'male',
            ])
            ->assertOk()
            ->json('changed_fields');

        $this->assertContains('number_of_children', $changed);
    }

    public function test_sending_the_same_values_reports_no_change(): void
    {
        $before = $this->guardian->user->updated_at;

        $this->asGuardian()
            ->putJson('/api/guardian/profile', [
                'user_name' => $this->guardian->user->user_name,
                'relationship' => 'father',
                'number_of_children' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('changed', false);

        $this->assertEquals($before, $this->guardian->user->fresh()->updated_at);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $this->asGuardian()
            ->putJson('/api/guardian/profile', [])
            ->assertStatus(422);
    }

    public function test_an_unknown_relationship_is_rejected(): void
    {
        $this->asGuardian()
            ->putJson('/api/guardian/profile', ['relationship' => 'uncle'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('relationship');
    }

    public function test_a_malformed_phone_is_rejected(): void
    {
        $this->asGuardian()
            ->putJson('/api/guardian/profile', ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_the_guardian_can_resend_their_own_phone(): void
    {
        // القيد unique ما لازم يرفض صاحب الرقم نفسه
        $this->asGuardian()
            ->putJson('/api/guardian/profile', [
                'phone' => $this->guardian->user->phone,
                'number_of_children' => 3,
            ])
            ->assertOk();
    }

    public function test_a_phone_used_by_another_account_is_rejected(): void
    {
        $other = $this->makeUser('student', 'Someone');

        $this->asGuardian()
            ->putJson('/api/guardian/profile', ['phone' => $other->phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_birth_date_in_the_wrong_format_is_rejected(): void
    {
        $this->asGuardian()
            ->putJson('/api/guardian/profile', ['birth_date' => '17-08-2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_zero_children_is_rejected(): void
    {
        $this->asGuardian()
            ->putJson('/api/guardian/profile', ['number_of_children' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('number_of_children');
    }

    public function test_the_email_cannot_be_changed_from_the_profile(): void
    {
        $email = $this->guardian->user->email;

        $this->asGuardian()
            ->putJson('/api/guardian/profile', [
                'email' => 'new@test.com',
                'number_of_children' => 5,
            ])
            ->assertOk();

        $this->assertSame($email, $this->guardian->user->fresh()->email);
    }

    public function test_the_update_returns_the_profile_in_the_same_shape_as_show(): void
    {
        $profile = $this->asGuardian()
            ->putJson('/api/guardian/profile', ['number_of_children' => 3])
            ->assertOk()
            ->json('data.profile');

        $this->assertSame(3, $profile['number_of_children']);
        $this->assertArrayHasKey('relationship_label', $profile);
        $this->assertArrayNotHasKey('students', $profile);
    }

    public function test_a_non_guardian_cannot_update_the_guardian_profile(): void
    {
        $supervisor = $this->makeSupervisor();

        $this->actingAs($supervisor->user, 'sanctum')
            ->putJson('/api/guardian/profile', ['relationship' => 'mother'])
            ->assertForbidden();
    }
}
