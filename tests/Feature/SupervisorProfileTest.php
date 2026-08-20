<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class SupervisorProfileTest extends TestCase
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

    // ==================== العرض ====================

    public function test_the_profile_returns_personal_and_professional_info(): void
    {
        $data = $this->asSupervisor()
            ->getJson('/api/supervisor/profile')
            ->assertOk()
            ->assertJsonPath('data.role', 'supervisor')
            ->json('data');

        // شخصية
        $this->assertSame($this->supervisor->user->user_name, $data['user_name']);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('phone', $data);

        // مهنية
        $this->assertSame('master', $data['profile']['educational_qualification']);
        $this->assertSame('الإشراف', $data['profile']['specialization']);
        $this->assertArrayHasKey('bio', $data['profile']);
    }

    public function test_the_qualification_has_an_arabic_label(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.educational_qualification', 'master')
            ->assertJsonPath('data.profile.qualification_label', 'ماجستير');
    }

    public function test_the_profile_carries_an_activity_summary(): void
    {
        Announcement::create([
            'supervisor_id' => $this->supervisor->id,
            'title' => 'إعلان',
            'description' => 'وصف',
            'type' => 'academic',
            'date' => '2026-08-16',
        ]);

        $this->asSupervisor()
            ->getJson('/api/supervisor/profile')
            ->assertOk()
            ->assertJsonPath('data.activity.announcements', 1)
            ->assertJsonPath('data.activity.student_attendance_records', 0)
            ->assertJsonPath('data.activity.substitutions_assigned', 0);
    }

    public function test_a_non_supervisor_is_blocked(): void
    {
        $this->actingAs($this->makeUser('teacher', 'معلم'), 'sanctum')
            ->getJson('/api/supervisor/profile')
            ->assertForbidden();
    }

    // ==================== التعديل ====================

    public function test_personal_and_professional_fields_update_together(): void
    {
        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', [
                'user_name' => 'الموجّه الجديد',
                'specialization' => 'الإرشاد النفسي',
            ])
            ->assertOk()
            ->assertJsonPath('changed', true);

        $this->assertSame('الموجّه الجديد', $this->supervisor->user->fresh()->user_name);
        $this->assertSame('الإرشاد النفسي', $this->supervisor->fresh()->specialization);
    }

    public function test_the_response_lists_which_fields_changed(): void
    {
        $changed = $this->asSupervisor()
            ->putJson('/api/supervisor/profile', ['bio' => 'نبذة محدّثة'])
            ->assertOk()
            ->json('changed_fields');

        $this->assertSame(['bio'], $changed);
    }

    public function test_sending_the_same_values_reports_no_change(): void
    {
        $before = $this->supervisor->updated_at;

        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', [
                'specialization' => $this->supervisor->specialization,
                'educational_qualification' => $this->supervisor->educational_qualification,
            ])
            ->assertOk()
            ->assertJsonPath('changed', false);

        $this->assertEquals($before, $this->supervisor->fresh()->updated_at);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لم تُرسل أي بيانات للتعديل');
    }

    public function test_an_unknown_qualification_is_rejected(): void
    {
        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', ['educational_qualification' => 'diploma'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('educational_qualification');
    }

    public function test_a_malformed_phone_is_rejected(): void
    {
        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_phone_used_by_another_user_is_rejected(): void
    {
        $other = $this->makeUser('teacher', 'معلم');

        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', ['phone' => $other->phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_keeping_your_own_phone_is_allowed(): void
    {
        // قاعدة unique لازم تستثني المستخدم نفسه
        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', [
                'phone' => $this->supervisor->user->phone,
                'bio' => 'نبذة جديدة',
            ])
            ->assertOk()
            ->assertJsonPath('changed', true);
    }

    public function test_a_future_birth_date_is_rejected(): void
    {
        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', ['birth_date' => now()->addYear()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_the_email_cannot_be_changed_here(): void
    {
        $original = $this->supervisor->user->email;

        // الإيميل مش ضمن الحقول المسموحة، فبينتجاهل
        $this->asSupervisor()
            ->putJson('/api/supervisor/profile', [
                'email' => 'new@test.com',
                'bio' => 'نبذة',
            ])
            ->assertOk();

        $this->assertSame($original, $this->supervisor->user->fresh()->email);
    }

    // ==================== السيرة الذاتية ====================
    //
    // العمود cv_file إلزامي بالداتابيز والتسجيل بيفرض رفع سيرة،
    // فما في موجّه بلا سيرة — كل رفع هو استبدال.

    /** رفع ملف عبر النقطة، مع هيدر JSON حتى ما يصير redirect عند الفشل */
    private function uploadCv(string $name = 'cv.pdf', string $mime = 'application/pdf')
    {
        return $this->asSupervisor()
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/supervisor/profile/cv', [
                'cv_file' => UploadedFile::fake()->create($name, 200, $mime),
            ]);
    }

    public function test_the_cv_can_be_replaced(): void
    {
        Storage::fake('local');

        $this->uploadCv()
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث السيرة الذاتية');

        Storage::disk('local')->assertExists($this->supervisor->fresh()->cv_file);
    }

    public function test_replacing_removes_the_previous_file(): void
    {
        Storage::fake('local');

        $this->uploadCv('first.pdf')->assertOk();
        $first = $this->supervisor->fresh()->cv_file;

        $this->uploadCv('second.pdf')
            ->assertOk()
            ->assertJsonPath('data.previous_file_name', basename($first));

        $second = $this->supervisor->fresh()->cv_file;

        $this->assertNotSame($first, $second);

        // الملف القديم ما بيضل يتراكم على القرص
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_a_non_document_file_is_rejected(): void
    {
        Storage::fake('local');

        $this->uploadCv('virus.exe', 'application/octet-stream')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cv_file');
    }

    public function test_the_cv_can_be_downloaded(): void
    {
        Storage::fake('local');

        $this->uploadCv()->assertOk();

        $this->asSupervisor()
            ->get('/api/supervisor/profile/cv')
            ->assertOk()
            ->assertDownload();
    }

    public function test_a_missing_file_on_disk_is_reported_clearly(): void
    {
        Storage::fake('local');

        // مسجّل بالداتابيز بس مفقود من القرص — بيصير لو انمسح يدوياً
        $this->supervisor->update(['cv_file' => 'supervisors/cv/ghost.pdf']);

        $this->asSupervisor()
            ->getJson('/api/supervisor/profile/cv')
            ->assertNotFound()
            ->assertJsonPath('message', 'ملف السيرة الذاتية مفقود من التخزين، أعد رفعه');
    }

    public function test_the_profile_shows_the_cv_file_name(): void
    {
        Storage::fake('local');

        $this->uploadCv()->assertOk();

        $expected = basename($this->supervisor->fresh()->cv_file);

        $this->asSupervisor()
            ->getJson('/api/supervisor/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.cv_file_name', $expected);
    }

    public function test_a_teacher_cannot_reach_the_cv_endpoints(): void
    {
        $teacher = $this->makeUser('teacher', 'معلم');

        $this->actingAs($teacher, 'sanctum')->getJson('/api/supervisor/profile/cv')->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/supervisor/profile/cv')->assertForbidden();
    }
}
