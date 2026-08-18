<?php

namespace Tests\Feature;

use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class SectionTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $classId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();

        // makeSection بينشئ الصف كمان، ومنمسح الشعبة حتى نبلّش من صفر
        $section = $this->makeSection('temp');
        $this->classId = $section->class_id;
        $section->delete();
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function send(array $sections)
    {
        return $this->asSupervisor()->postJson('/api/supervisor/sections', [
            'class_id' => $this->classId,
            'sections' => $sections,
        ]);
    }

    public function test_two_sections_are_both_created(): void
    {
        $this->send([
            ['name' => 'أ', 'capacity' => 30],
            ['name' => 'ب', 'capacity' => 25],
        ])
            ->assertCreated()
            ->assertJsonPath('data.received_count', 2)
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.updated_count', 0);

        $this->assertSame(2, Section::where('class_id', $this->classId)->count());
    }

    public function test_resending_the_same_section_updates_instead_of_creating(): void
    {
        $this->send([['name' => 'أ', 'capacity' => 30]])->assertCreated();

        // نفس الشعبة مرة تانية — لازم يقول تحديث مش إنشاء
        $this->send([['name' => 'أ', 'capacity' => 40]])
            ->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.updated_count', 1);

        // ولا صف مكرر بالداتابيز
        $this->assertSame(1, Section::where('class_id', $this->classId)->count());
        $this->assertSame(40, Section::where('class_id', $this->classId)->first()->capacity);
    }

    public function test_a_mixed_request_reports_created_and_updated_separately(): void
    {
        $this->send([['name' => 'أ', 'capacity' => 30]])->assertCreated();

        // وحدة موجودة ووحدة جديدة — هاي الحالة يلي كانت مربكة
        $this->send([
            ['name' => 'أ', 'capacity' => 35],
            ['name' => 'ب', 'capacity' => 20],
        ])
            ->assertCreated()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.updated_count', 1);

        $this->assertSame(2, Section::where('class_id', $this->classId)->count());
    }

    public function test_a_duplicate_name_inside_one_request_is_rejected(): void
    {
        $this->send([
            ['name' => 'أ', 'capacity' => 30],
            ['name' => 'أ', 'capacity' => 25],
        ])->assertStatus(422);

        $this->assertSame(0, Section::where('class_id', $this->classId)->count());
    }

    public function test_whitespace_around_the_name_is_trimmed(): void
    {
        $this->send([['name' => '  أ  ', 'capacity' => 30]])->assertCreated();

        $this->assertSame('أ', Section::where('class_id', $this->classId)->first()->name);

        // نفس الاسم بمسافات لازم يتعرّف عليه كموجود
        $this->send([['name' => 'أ', 'capacity' => 30]])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertSame(1, Section::where('class_id', $this->classId)->count());
    }

    public function test_capacity_cannot_drop_below_the_current_student_count(): void
    {
        $this->send([['name' => 'أ', 'capacity' => 30]])->assertCreated();

        $section = Section::where('class_id', $this->classId)->first();

        $this->makeStudent($section, '10001');
        $this->makeStudent($section, '10002');
        $this->makeStudent($section, '10003');

        $this->send([['name' => 'أ', 'capacity' => 2]])
            ->assertStatus(422);

        $this->assertSame(30, $section->fresh()->capacity);
    }

    public function test_single_update_also_guards_the_capacity(): void
    {
        $this->send([['name' => 'أ', 'capacity' => 30]])->assertCreated();

        $section = Section::where('class_id', $this->classId)->first();
        $this->makeStudent($section, '10001');
        $this->makeStudent($section, '10002');

        $this->asSupervisor()
            ->putJson("/api/supervisor/sections/{$section->id}", ['capacity' => 1])
            ->assertStatus(422);

        // بس السعة المساوية لعدد الطلاب مسموحة
        $this->asSupervisor()
            ->putJson("/api/supervisor/sections/{$section->id}", ['capacity' => 2])
            ->assertOk();
    }

    public function test_renaming_onto_an_existing_name_is_rejected(): void
    {
        $this->send([
            ['name' => 'أ', 'capacity' => 30],
            ['name' => 'ب', 'capacity' => 30],
        ])->assertCreated();

        $sectionB = Section::where('class_id', $this->classId)->where('name', 'ب')->first();

        $this->asSupervisor()
            ->putJson("/api/supervisor/sections/{$sectionB->id}", ['name' => 'أ'])
            ->assertStatus(422);

        $this->assertSame('ب', $sectionB->fresh()->name);
    }

    // ==================== العرض ====================

    public function test_an_empty_class_returns_success_with_an_empty_list(): void
    {
        // نتيجة فاضية مش خطأ — الصف موجود بس ما فيه شعب
        $this->asSupervisor()
            ->getJson('/api/supervisor/students/sections-overview?class_id='.$this->classId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'لا توجد شعب في هذا الصف بعد')
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.sections')
            // بس معلومات الصف بترجع، حتى تعرف الواجهة شو تعرض
            ->assertJsonPath('data.class.id', $this->classId);
    }

    public function test_the_overview_returns_sections_with_their_counts(): void
    {
        $this->send([
            ['name' => 'أ', 'capacity' => 30],
            ['name' => 'ب', 'capacity' => 20],
        ])->assertCreated();

        $sectionA = Section::where('class_id', $this->classId)->where('name', 'أ')->first();
        $this->makeStudent($sectionA, '10001');
        $this->makeStudent($sectionA, '10002');

        $response = $this->asSupervisor()
            ->getJson('/api/supervisor/students/sections-overview?class_id='.$this->classId)
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.total_capacity', 50)
            ->assertJsonPath('data.total_students', 2)
            ->assertJsonPath('data.available_slots', 48);

        $first = $response->json('data.sections.0');

        $this->assertSame('أ', $first['name']);
        $this->assertSame(2, $first['students_count']);
        $this->assertSame(28, $first['available_slots']);
        $this->assertFalse($first['is_full']);
    }

    public function test_a_full_section_is_flagged(): void
    {
        $this->send([['name' => 'أ', 'capacity' => 2]])->assertCreated();

        $section = Section::where('class_id', $this->classId)->first();
        $this->makeStudent($section, '10001');
        $this->makeStudent($section, '10002');

        $this->asSupervisor()
            ->getJson('/api/supervisor/students/sections-overview?class_id='.$this->classId)
            ->assertOk()
            ->assertJsonPath('data.sections.0.is_full', true)
            ->assertJsonPath('data.sections.0.available_slots', 0);
    }

    public function test_the_section_list_can_be_filtered_by_class(): void
    {
        $this->send([['name' => 'أ', 'capacity' => 30]])->assertCreated();

        $this->asSupervisor()
            ->getJson('/api/supervisor/sections?class_id='.$this->classId)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.sections.0.class_id', $this->classId);
    }

    public function test_the_section_list_is_empty_when_nothing_exists(): void
    {
        $this->asSupervisor()
            ->getJson('/api/supervisor/sections')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 0);
    }

    public function test_capacity_is_required_when_creating(): void
    {
        $this->send([['name' => 'أ']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sections.0.capacity');
    }
}
