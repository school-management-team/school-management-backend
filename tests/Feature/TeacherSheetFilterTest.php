<?php

namespace Tests\Feature;

use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * كشف حضور المعلمين مع فلاتر المرحلة والمادة.
 * القائمة الفاضية نتيجة صحيحة، بس لازم توضّح السبب.
 */
class TeacherSheetFilterTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $primary;
    private $math;
    private $physics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();

        $this->primary = $this->makeStage('primary');
        $scientific = $this->makeStage('high_scientific');

        $this->math = $this->makeSubject('رياضيات');
        $this->physics = $this->makeSubject('فيزياء');

        // الرياضيات مقررة بالابتدائي، والفيزياء لأ
        $this->primary->subjects()->sync([$this->math->id]);
        $scientific->subjects()->sync([$this->math->id, $this->physics->id]);

        $this->makeTeacher($this->math, $this->primary, 'أستاذ الرياضيات');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function sheet(array $filters = [])
    {
        return $this->asSupervisor()->getJson('/api/supervisor/attendance/teachers?'.http_build_query($filters));
    }

    public function test_a_subject_not_taught_in_the_stage_says_so(): void
    {
        // الفيزياء مش مقررة بالابتدائي
        $this->sheet(['stage_id' => $this->primary->id, 'subject_id' => $this->physics->id])
            ->assertOk()
            ->assertJsonPath('message', 'مادة فيزياء غير مقررة في مرحلة primary')
            ->assertJsonCount(0, 'data.teachers');
    }

    public function test_a_taught_subject_with_no_teachers_says_so(): void
    {
        // الرياضيات مقررة بالابتدائي بس منشيل معلمها
        \App\Models\Teacher::query()->delete();

        $this->sheet(['stage_id' => $this->primary->id, 'subject_id' => $this->math->id])
            ->assertOk()
            ->assertJsonPath('message', 'لا يوجد معلمون لمادة رياضيات في مرحلة primary');
    }

    public function test_filtering_by_subject_alone_explains_the_gap(): void
    {
        $this->sheet(['subject_id' => $this->physics->id])
            ->assertOk()
            ->assertJsonPath('message', 'لا يوجد معلمون لمادة فيزياء');
    }

    public function test_filtering_by_stage_alone_explains_the_gap(): void
    {
        $literary = $this->makeStage('high_literary');

        $this->sheet(['stage_id' => $literary->id])
            ->assertOk()
            ->assertJsonPath('message', 'لا يوجد معلمون في مرحلة high_literary');
    }

    public function test_no_teachers_at_all_says_so(): void
    {
        \App\Models\Teacher::query()->delete();

        $this->sheet()
            ->assertOk()
            ->assertJsonPath('message', 'لا يوجد معلمون مسجّلون في النظام');
    }

    public function test_a_matching_filter_returns_the_teachers(): void
    {
        $this->sheet(['stage_id' => $this->primary->id, 'subject_id' => $this->math->id])
            ->assertOk()
            ->assertJsonPath('message', 'عدد المعلمين: 1')
            ->assertJsonCount(1, 'data.teachers')
            ->assertJsonPath('data.teachers.0.subject', 'رياضيات');
    }
}
