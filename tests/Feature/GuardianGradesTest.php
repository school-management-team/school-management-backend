<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class GuardianGradesTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private Guardian $guardian;
    private Student $child;
    private Student $otherChild;
    private $mathAssignment;
    private $arabicAssignment;

    protected function setUp(): void
    {
        parent::setUp();

        $stage = $this->makeStage();
        $math = $this->makeSubject('رياضيات');
        $arabic = $this->makeSubject('عربي');
        $section = $this->makeSection('A');

        $this->child = $this->makeStudent($section, '10001');
        $this->otherChild = $this->makeStudent($section, '10002');

        $this->mathAssignment = $this->makeAssignment($this->makeTeacher($math, $stage, 'Math'), $math, $section);
        $this->arabicAssignment = $this->makeAssignment($this->makeTeacher($arabic, $stage, 'Arabic'), $arabic, $section);

        $this->guardian = Guardian::create([
            'relationship' => 'father',
            'number_of_children' => 1,
            'user_id' => $this->makeUser('guardian', 'Parent')->id,
        ]);

        $this->guardian->students()->attach($this->child->id);
    }

    private function actingAsGuardian(): self
    {
        return $this->actingAs($this->guardian->user, 'sanctum');
    }

    private function grade(Student $student, $assignment, array $values, bool $approved = true): void
    {
        foreach ($values as $type => $value) {
            Grade::create([
                'student_id' => $student->id,
                'teacher_assignment_id' => $assignment->id,
                'subject_id' => $assignment->subject_id,
                'section_id' => $assignment->section_id,
                'type' => $type,
                'semester' => 1,
                'value' => $value,
                'status' => 'approved',
            ]);
        }

        GradeSubmission::updateOrCreate(
            [
                'subject_id' => $assignment->subject_id,
                'section_id' => $assignment->section_id,
                'semester' => 1,
            ],
            [
                'teacher_assignment_id' => $assignment->id,
                'status' => $approved ? 'approved' : 'submitted',
            ]
        );
    }

    private function fullMarks(): array
    {
        return ['participation' => 80, 'quiz' => 60, 'exam' => 90];
    }

    private function report()
    {
        return $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/grades?semester=1");
    }

    // ==================== العرض ====================

    public function test_guardian_sees_approved_grades(): void
    {
        $this->grade($this->child, $this->mathAssignment, $this->fullMarks());

        $response = $this->report()->assertOk()
            ->assertJsonPath('data.student.student_number', '10001');

        $mathRow = collect($response->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'رياضيات');

        $this->assertEquals(79.0, $mathRow['total_value']);   // 80×20% + 60×30% + 90×50%
        $this->assertTrue($mathRow['passed']);
    }

    public function test_the_component_breakdown_is_visible_for_approved_subjects(): void
    {
        $this->grade($this->child, $this->mathAssignment, $this->fullMarks());

        $mathRow = collect($this->report()->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'رياضيات');

        $participation = collect($mathRow['components'])->firstWhere('type', 'participation');

        $this->assertEquals(80.0, $participation['value']);
        $this->assertSame(20, $participation['weight']);
    }

    // ==================== حجب غير المعتمد ====================

    public function test_unapproved_values_are_hidden_from_the_guardian(): void
    {
        $this->grade($this->child, $this->arabicAssignment, $this->fullMarks(), approved: false);

        $arabicRow = collect($this->report()->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'عربي');

        // المادة ظاهرة بالاسم والحالة...
        $this->assertSame('submitted', $arabicRow['status']);
        $this->assertFalse($arabicRow['is_final']);

        // ...بس بدون ولا رقم
        $this->assertNull($arabicRow['total_value']);
        $this->assertNull($arabicRow['passed']);

        foreach ($arabicRow['components'] as $component) {
            $this->assertNull($component['value'], "المكوّن {$component['type']} تسرّب رغم أنه غير معتمد");
            $this->assertNull($component['weighted_value']);
        }
    }

    public function test_an_ungraded_subject_shows_as_draft_with_no_values(): void
    {
        $this->grade($this->child, $this->mathAssignment, $this->fullMarks());

        $arabicRow = collect($this->report()->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'عربي');

        $this->assertSame('draft', $arabicRow['status']);
        $this->assertNull($arabicRow['total_value']);
    }

    public function test_the_average_only_uses_approved_subjects(): void
    {
        $this->grade($this->child, $this->mathAssignment, $this->fullMarks());                              // 79، معتمدة
        $this->grade($this->child, $this->arabicAssignment, ['participation' => 20, 'quiz' => 20, 'exam' => 20], approved: false);

        $summary = $this->report()->json('data.semesters.0.summary');

        $this->assertEquals(79.0, $summary['average']);
        $this->assertSame(1, $summary['counted_subjects']);
        $this->assertSame(1, $summary['pending_subjects']);
        $this->assertFalse($summary['is_complete']);
    }

    public function test_the_supervisor_still_sees_unapproved_values(): void
    {
        $this->grade($this->child, $this->arabicAssignment, $this->fullMarks(), approved: false);

        $supervisor = $this->makeSupervisor();

        $arabicRow = collect($this->actingAs($supervisor->user, 'sanctum')
            ->getJson("/api/supervisor/students/{$this->child->id}/report-card?semester=1")
            ->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'عربي');

        // الموجّه بيشوف الأرقام — الحجب لولي الأمر بس
        $this->assertEquals(79.0, $arabicRow['total_value']);
    }

    // ==================== الفصول والعزل ====================

    public function test_both_semesters_are_returned_when_none_is_given(): void
    {
        $this->grade($this->child, $this->mathAssignment, $this->fullMarks());

        $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/grades")
            ->assertOk()
            ->assertJsonCount(2, 'data.semesters');
    }

    public function test_an_invalid_semester_is_rejected(): void
    {
        $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/grades?semester=7")
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester');
    }

    public function test_a_guardian_cannot_read_another_students_grades(): void
    {
        $this->grade($this->otherChild, $this->mathAssignment, $this->fullMarks());

        $this->actingAsGuardian()
            ->getJson("/api/guardian/children/{$this->otherChild->id}/grades?semester=1")
            ->assertForbidden();
    }

    public function test_a_child_without_a_section_is_rejected(): void
    {
        $this->child->update(['section_id' => null]);

        $this->report()->assertStatus(422);
    }

    public function test_other_roles_are_blocked(): void
    {
        $this->actingAs($this->makeUser('teacher', 'Nosy'), 'sanctum')
            ->getJson("/api/guardian/children/{$this->child->id}/grades")
            ->assertForbidden();
    }
}
