<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Section;
use App\Models\StudentFee;
use App\Models\TeacherAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * إرسال نفس القيم المحفوظة مش خطأ، بس ما بصير نقول "تم التعديل"
 * ونحنا ما كتبنا ولا حقل.
 */
class NoChangeUpdateTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $section;
    private $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->section = $this->makeSection('أ');
        $this->subject = $this->makeSubject('رياضيات');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function makeAnnouncement(): Announcement
    {
        return Announcement::create([
            'supervisor_id' => $this->supervisor->id,
            'title' => 'اجتماع',
            'description' => 'وصف',
            'type' => 'academic',
            'date' => '2026-08-16',
            'is_important' => false,
        ]);
    }

    public function test_resending_identical_announcement_values_reports_no_change(): void
    {
        $a = $this->makeAnnouncement();
        $before = $a->updated_at;

        $this->asSupervisor()
            ->postJson("/api/supervisor/announcements/{$a->id}", [
                'title' => 'اجتماع',
                'description' => 'وصف',
                'type' => 'academic',
                'date' => '2026-08-16',
            ])
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('message', 'لم يطرأ أي تغيير — القيم المرسلة مطابقة للمحفوظة');

        // ولا updated_at تغيّر
        $this->assertEquals($before, $a->fresh()->updated_at);
    }

    public function test_a_real_announcement_change_lists_the_changed_fields(): void
    {
        $a = $this->makeAnnouncement();

        $this->asSupervisor()
            ->postJson("/api/supervisor/announcements/{$a->id}", ['title' => 'عنوان جديد'])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('changed_fields', ['title']);

        $this->assertSame('عنوان جديد', $a->fresh()->title);
    }

    public function test_resending_the_same_section_values_reports_no_change(): void
    {
        $this->asSupervisor()
            ->putJson("/api/supervisor/sections/{$this->section->id}", [
                'name' => $this->section->name,
                'capacity' => $this->section->capacity,
            ])
            ->assertOk()
            ->assertJsonPath('changed', false);
    }

    public function test_a_real_section_change_is_reported(): void
    {
        $this->asSupervisor()
            ->putJson("/api/supervisor/sections/{$this->section->id}", ['capacity' => 44])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('changed_fields', ['capacity']);
    }

    public function test_resending_the_same_assignment_values_reports_no_change(): void
    {
        $teacher = $this->makeTeacher($this->subject, $this->makeStage());
        $assignment = $this->makeAssignment($teacher, $this->subject, $this->section);

        $this->asSupervisor()
            ->putJson("/api/supervisor/teacher-assignments/{$assignment->id}", [
                'teacher_id' => $teacher->id,
                'subject_id' => $this->subject->id,
                'section_id' => $this->section->id,
            ])
            ->assertOk()
            ->assertJsonPath('changed', false);

        $this->assertSame(1, TeacherAssignment::count());
    }

    public function test_resending_the_same_fee_values_reports_no_change(): void
    {
        $student = $this->makeStudent($this->section, '10001');

        $fee = StudentFee::create([
            'student_id' => $student->id,
            'academic_year' => '2026-2027',
            'total_amount' => 1000000,
            'discount' => 0,
        ]);

        $this->asSupervisor()
            ->putJson("/api/supervisor/fees/{$fee->id}", [
                'total_amount' => 1000000,
                'discount' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('changed', false);
    }

    public function test_an_empty_body_is_still_rejected(): void
    {
        // فرق بين "ما بعتت شي" و"بعتت نفس القيم"
        $this->asSupervisor()
            ->putJson("/api/supervisor/sections/{$this->section->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لم تُرسل أي بيانات للتعديل');
    }

    // ==================== الصف والمادة ====================

    public function test_resending_the_same_class_values_reports_no_change(): void
    {
        $class = \App\Models\SchoolClass::find($this->section->class_id);

        $this->asSupervisor()
            ->putJson("/api/supervisor/classes/{$class->id}", [
                'name' => $class->name,
                'grade_order' => $class->grade_order,
                'stage_id' => $class->stage_id,
            ])
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('message', 'لم يطرأ أي تغيير — القيم المرسلة مطابقة للمحفوظة');

        $this->assertEquals($class->updated_at, $class->fresh()->updated_at);
    }

    public function test_a_real_class_change_is_reported(): void
    {
        $class = \App\Models\SchoolClass::find($this->section->class_id);

        $this->asSupervisor()
            ->putJson("/api/supervisor/classes/{$class->id}", ['name' => 'الصف الأول المعدّل'])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('changed_fields', ['name']);

        $this->assertSame('الصف الأول المعدّل', $class->fresh()->name);
    }

    public function test_resending_the_same_subject_values_reports_no_change(): void
    {
        $this->asSupervisor()
            ->putJson("/api/supervisor/subjects/{$this->subject->id}", [
                'name' => $this->subject->name,
                'passing_grade' => $this->subject->passing_grade,
            ])
            ->assertOk()
            ->assertJsonPath('changed', false);
    }

    public function test_resending_the_same_stage_links_reports_no_change(): void
    {
        // المراحل علاقة منفصلة — مزامنة بنفس القائمة مش تعديل
        $stageIds = $this->subject->stages()->pluck('stages.id')->all();

        $this->asSupervisor()
            ->putJson("/api/supervisor/subjects/{$this->subject->id}", [
                'stage_ids' => array_reverse($stageIds),   // نفس المجموعة بترتيب مقلوب
            ])
            ->assertOk()
            ->assertJsonPath('changed', false);
    }

    public function test_changing_the_stage_links_is_reported(): void
    {
        $middle = $this->makeStage('middle');
        $this->subject->stages()->sync([]);

        $this->asSupervisor()
            ->putJson("/api/supervisor/subjects/{$this->subject->id}", [
                'stage_ids' => [$middle->id],
            ])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('changed_fields', ['stages']);

        $this->assertSame(1, $this->subject->stages()->count());
    }
}
