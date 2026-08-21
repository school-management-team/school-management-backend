<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * توزيع الطلاب على الشعب.
 * الرسالة لازم تعكس اللي صار: كم طالب انوزّع فعلاً، مش نص ثابت.
 */
class StudentAssignTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private Section $sectionA;
    private int $classId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->sectionA = $this->makeSection('أ');
        $this->classId = $this->sectionA->class_id;
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function assign(bool $reset = false)
    {
        return $this->asSupervisor()->postJson('/api/supervisor/students/assign-sections', [
            'class_id' => $this->classId,
            'reset' => $reset,
        ]);
    }

    private int $nextNumber = 20001;

    /** طلاب بلا شعبة — العدّاد بيضمن أرقام فريدة عبر الاستدعاءات المتعددة */
    private function makeUnassigned(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $student = $this->makeStudent($this->sectionA, (string) $this->nextNumber);
            $student->update(['section_id' => null]);
            $this->nextNumber++;
        }
    }

    public function test_unassigned_students_are_distributed(): void
    {
        $this->makeUnassigned(4);

        $this->assign()
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('message', 'تم توزيع 4 طالب')
            ->assertJsonPath('data.assigned_count', 4)
            ->assertJsonPath('data.unassigned_count', 0);

        $this->assertSame(0, Student::whereNull('section_id')->count());
    }

    public function test_running_it_again_says_everyone_is_already_placed(): void
    {
        $this->makeUnassigned(3);
        $this->assign()->assertOk();

        // كلهم موزّعين — ما في شي ينعمل
        $this->assign()
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('message', 'جميع طلاب هذا الصف موزّعون على الشعب مسبقاً')
            ->assertJsonPath('data.assigned_count', 0);
    }

    public function test_a_class_with_no_students_says_so(): void
    {
        $this->assign()
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('data.assigned_count', 0);
    }

    public function test_a_full_class_reports_who_was_left_out(): void
    {
        // سعة الشعبة 2 بس، والطلاب 5
        $this->sectionA->update(['capacity' => 2]);
        $this->makeUnassigned(5);

        $response = $this->assign()
            ->assertOk()
            ->assertJsonPath('data.assigned_count', 2)
            ->assertJsonPath('data.unassigned_count', 3);

        $this->assertStringContainsString('وبقي 3 بلا شعبة', $response->json('message'));
    }

    public function test_nothing_fits_is_reported_honestly(): void
    {
        $this->sectionA->update(['capacity' => 1]);
        $this->makeUnassigned(1);
        $this->assign()->assertOk();          // امتلأت

        $this->makeUnassigned(2);             // طالبين جداد بلا مقاعد

        $response = $this->assign()
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('data.assigned_count', 0)
            ->assertJsonPath('data.unassigned_count', 2);

        $this->assertStringContainsString('لا توجد مقاعد شاغرة', $response->json('message'));
    }

    public function test_reset_redistributes_everyone(): void
    {
        $this->makeUnassigned(4);
        $this->assign()->assertOk();

        $this->assign(reset: true)
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('message', 'تم إعادة توزيع 4 طالب');
    }

    public function test_the_response_carries_seat_totals(): void
    {
        $this->makeSection('ب');
        $this->makeUnassigned(3);

        $this->assign()
            ->assertOk()
            ->assertJsonPath('data.total_capacity', 60)   // شعبتين × 30
            ->assertJsonPath('data.total_students', 3)
            ->assertJsonPath('data.available_slots', 57);
    }

    public function test_a_class_without_sections_is_rejected(): void
    {
        $stage = $this->makeStage();
        $empty = SchoolClass::create(['name' => 'صف بلا شعب', 'grade_order' => 9, 'stage_id' => $stage->id]);

        $this->asSupervisor()->postJson('/api/supervisor/students/assign-sections', [
            'class_id' => $empty->id,
        ])->assertNotFound();
    }

    // ==================== النقل بين الشعب ====================

    private function transfer(array $studentIds, int $toSectionId)
    {
        return $this->asSupervisor()->postJson('/api/supervisor/students/transfer-section', [
            'student_ids' => $studentIds,
            'to_section_id' => $toSectionId,
        ]);
    }

    public function test_students_move_to_another_section(): void
    {
        $sectionB = $this->makeSection('ب');
        $one = $this->makeStudent($this->sectionA, '30001');
        $two = $this->makeStudent($this->sectionA, '30002');

        $this->transfer([$one->id, $two->id], $sectionB->id)
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('message', 'تم نقل 2 طالب إلى شعبة ب')
            ->assertJsonPath('data.transferred_count', 2)
            ->assertJsonPath('data.already_there_count', 0);

        $this->assertSame($sectionB->id, $one->fresh()->section_id);
    }

    public function test_moving_to_the_same_section_says_nothing_moved(): void
    {
        $student = $this->makeStudent($this->sectionA, '30003');

        $this->transfer([$student->id], $this->sectionA->id)
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('message', 'الطالب موجود في هذه الشعبة مسبقاً، لم يتم نقل أحد')
            ->assertJsonPath('data.transferred_count', 0)
            ->assertJsonPath('data.already_there_count', 1);
    }

    public function test_a_mixed_batch_reports_both_numbers(): void
    {
        $sectionB = $this->makeSection('ب');
        $inA = $this->makeStudent($this->sectionA, '30004');
        $inB = $this->makeStudent($this->sectionA, '30005');
        $inB->update(['section_id' => $sectionB->id]);

        // واحد بحاجة نقل وواحد موجود أصلاً
        $response = $this->transfer([$inA->id, $inB->id], $sectionB->id)
            ->assertOk()
            ->assertJsonPath('data.transferred_count', 1)
            ->assertJsonPath('data.already_there_count', 1)
            ->assertJsonPath('data.requested_count', 2);

        $this->assertStringContainsString('كانوا فيها مسبقاً', $response->json('message'));
    }

    public function test_the_transfer_records_the_previous_section(): void
    {
        $sectionB = $this->makeSection('ب');
        $student = $this->makeStudent($this->sectionA, '30006');

        $this->transfer([$student->id], $sectionB->id)
            ->assertOk()
            ->assertJsonPath('data.transferred.0.from_section_id', $this->sectionA->id)
            ->assertJsonPath('data.transferred.0.new_section_id', $sectionB->id);
    }

    public function test_a_transfer_beyond_capacity_is_rejected(): void
    {
        $sectionB = $this->makeSection('ب');
        $sectionB->update(['capacity' => 1]);

        $one = $this->makeStudent($this->sectionA, '30007');
        $two = $this->makeStudent($this->sectionA, '30008');

        $this->transfer([$one->id, $two->id], $sectionB->id)
            ->assertStatus(422)
            ->assertJsonPath('data.available_slots', 1)
            ->assertJsonPath('data.requested', 2);

        $this->assertSame($this->sectionA->id, $one->fresh()->section_id);
    }

    public function test_the_target_section_state_comes_back(): void
    {
        $sectionB = $this->makeSection('ب');
        $student = $this->makeStudent($this->sectionA, '30009');

        $this->transfer([$student->id], $sectionB->id)
            ->assertOk()
            ->assertJsonPath('data.target_section.current_count', 1)
            ->assertJsonPath('data.target_section.available_slots', 29);
    }
}
