<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\TeacherAssignment;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class TeacherAssignmentTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $teacher;
    private $math;
    private $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $this->math = $this->makeSubject('رياضيات');
        $this->teacher = $this->makeTeacher($this->math, $this->makeStage(), 'أستاذ الرياضيات');
        $this->section = $this->makeSection('أ');
    }

    private function asSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function link(array $overrides = [])
    {
        return $this->asSupervisor()->postJson('/api/supervisor/teacher-assignments', array_merge([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
        ], $overrides));
    }

    public function test_a_teacher_can_be_linked_to_a_subject_and_section(): void
    {
        $this->link()
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(1, TeacherAssignment::count());
    }

    public function test_linking_the_same_thing_twice_is_rejected(): void
    {
        $this->link()->assertCreated();

        // نفس المعلم ونفس المادة ونفس الشعبة — لازم يقول إنه مربوط أصلاً
        $response = $this->link()
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('مكلّف أصلاً', $response->json('message'));

        // وما انخلق تكليف تاني
        $this->assertSame(1, TeacherAssignment::count());
    }

    public function test_the_rejection_message_names_the_teacher_and_subject(): void
    {
        $this->link()->assertCreated();

        $message = $this->link()->json('message');

        $this->assertStringContainsString('أستاذ الرياضيات', $message);
        $this->assertStringContainsString('رياضيات', $message);
        $this->assertStringContainsString('أ', $message);
    }

    public function test_the_same_teacher_can_take_another_section(): void
    {
        $this->link()->assertCreated();

        $sectionB = $this->makeSection('ب');

        $this->link(['section_id' => $sectionB->id])->assertCreated();

        $this->assertSame(2, TeacherAssignment::count());
    }

    public function test_the_same_teacher_can_take_another_subject(): void
    {
        $this->link()->assertCreated();

        $arabic = $this->makeSubject('عربي');

        $this->link(['subject_id' => $arabic->id])->assertCreated();

        $this->assertSame(2, TeacherAssignment::count());
    }

    public function test_two_teachers_can_share_the_same_subject_and_section(): void
    {
        $this->link()->assertCreated();

        // التدريس المشترك مسموح — بس دفتر العلامات بيضل واحد
        $second = $this->makeTeacher($this->math, $this->makeStage(), 'أستاذ رياضيات ثاني');

        $this->link(['teacher_id' => $second->id])->assertCreated();

        $this->assertSame(2, TeacherAssignment::count());
    }

    public function test_updating_onto_an_existing_assignment_is_rejected(): void
    {
        $this->link()->assertCreated();

        $sectionB = $this->makeSection('ب');
        $this->link(['section_id' => $sectionB->id])->assertCreated();

        $second = TeacherAssignment::where('section_id', $sectionB->id)->first();

        // نحاول نخلّي التاني نسخة من الأول
        $this->asSupervisor()
            ->putJson("/api/supervisor/teacher-assignments/{$second->id}", [
                'section_id' => $this->section->id,
            ])
            ->assertStatus(422);

        $this->assertSame($sectionB->id, $second->fresh()->section_id);
    }

    // ==================== قاعدة المرحلة ====================

    /** شعبة من مرحلة تانية (إعدادي) */
    private function makeMiddleSection()
    {
        $middle = $this->makeStage('middle');

        $class = \App\Models\SchoolClass::firstOrCreate(
            ['name' => 'الصف 7 إعدادي', 'stage_id' => $middle->id],
            ['grade_order' => 7]
        );

        return \App\Models\Section::create([
            'name' => 'أ',
            'class_id' => $class->id,
            'capacity' => 30,
        ]);
    }

    public function test_a_teacher_cannot_be_assigned_outside_his_stage(): void
    {
        // المعلم ابتدائي، والشعبة إعدادي
        $middleSection = $this->makeMiddleSection();

        $response = $this->link(['section_id' => $middleSection->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.teacher_stage', 'primary')
            ->assertJsonPath('data.class_stage', 'middle');

        $this->assertStringContainsString('مرحلته', $response->json('message'));
        $this->assertSame(0, TeacherAssignment::count());
    }

    public function test_a_teacher_can_take_many_subjects_inside_his_stage(): void
    {
        // كذا مادة بنفس المرحلة = مسموح، هاي القاعدة المطلوبة
        $this->link()->assertCreated();
        $this->link(['subject_id' => $this->makeSubject('عربي')->id])->assertCreated();
        $this->link(['subject_id' => $this->makeSubject('علوم')->id])->assertCreated();

        $this->assertSame(3, TeacherAssignment::count());
    }

    public function test_a_middle_stage_teacher_can_take_a_middle_section(): void
    {
        $middleSection = $this->makeMiddleSection();

        $middleTeacher = $this->makeTeacher(
            $this->math,
            $this->makeStage('middle'),
            'أستاذ رياضيات إعدادي'
        );

        $this->link([
            'teacher_id' => $middleTeacher->id,
            'section_id' => $middleSection->id,
        ])->assertCreated();

        $this->assertSame(1, TeacherAssignment::count());
    }

    public function test_moving_an_assignment_outside_the_stage_is_rejected(): void
    {
        $this->link()->assertCreated();

        $assignment = TeacherAssignment::first();
        $middleSection = $this->makeMiddleSection();

        $this->asSupervisor()
            ->putJson("/api/supervisor/teacher-assignments/{$assignment->id}", [
                'section_id' => $middleSection->id,
            ])
            ->assertStatus(422);

        $this->assertSame($this->section->id, $assignment->fresh()->section_id);
    }

    public function test_swapping_the_teacher_to_one_from_another_stage_is_rejected(): void
    {
        $this->link()->assertCreated();

        $assignment = TeacherAssignment::first();

        $middleTeacher = $this->makeTeacher($this->math, $this->makeStage('middle'), 'معلم إعدادي');

        // الشعبة ابتدائي والمعلم الجديد إعدادي
        $this->asSupervisor()
            ->putJson("/api/supervisor/teacher-assignments/{$assignment->id}", [
                'teacher_id' => $middleTeacher->id,
            ])
            ->assertStatus(422);

        $this->assertSame($this->teacher->id, $assignment->fresh()->teacher_id);
    }

    public function test_an_assignment_can_be_deleted(): void
    {
        $this->link()->assertCreated();

        $assignment = TeacherAssignment::first();

        $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertOk();

        $this->assertSame(0, TeacherAssignment::count());
    }

    // ==================== الحذف وأثره على باقي البيانات ====================

    private function scheduleLesson(TeacherAssignment $assignment, int $period): WeeklySchedule
    {
        return WeeklySchedule::create([
            'teacher_id' => $assignment->teacher_id,
            'teacher_assignment_id' => $assignment->id,
            'day_of_week' => 'sunday',
            'period_number' => $period,
            'start_time' => '08:00:00',
            'end_time' => '08:45:00',
            'type' => 'class',
        ]);
    }

    public function test_deleting_an_assignment_with_lessons_needs_confirmation(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();

        $this->scheduleLesson($assignment, 1);
        $this->scheduleLesson($assignment, 2);

        $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.lessons_count', 2);

        // ولا شي انحذف بدون تأكيد
        $this->assertSame(1, TeacherAssignment::count());
        $this->assertSame(2, WeeklySchedule::count());
    }

    public function test_the_warning_explains_why_the_lessons_go(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();
        $this->scheduleLesson($assignment, 1);

        $response = $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertStatus(409);

        $message = $response->json('message');

        // السبب: المادة بتنقرأ من التكليف
        $this->assertStringContainsString('مادة الحصة تُقرأ من التكليف', $message);
        $this->assertStringContainsString('خاناتها فارغة', $message);

        // والبديل الصحيح لتبديل المعلم
        $this->assertStringContainsString('PUT', $message);
    }

    public function test_the_warning_lists_what_survives(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();
        $this->scheduleLesson($assignment, 1);

        $data = $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertStatus(409)
            ->json('data');

        $this->assertContains('العلامات', $data['preserved']);
        $this->assertContains('المعلم', $data['preserved']);
        $this->assertContains('المادة', $data['preserved']);

        $this->assertSame('أستاذ الرياضيات', $data['assignment']['teacher']);
        $this->assertSame('رياضيات', $data['assignment']['subject']);
        $this->assertStringContainsString('teacher-assignments/'.$assignment->id, $data['alternative']);
    }

    public function test_swapping_the_teacher_keeps_the_lessons(): void
    {
        // الطريقة الصحيحة لتبديل المعلم بدون خسارة الجدول
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();

        $this->scheduleLesson($assignment, 1);
        $this->scheduleLesson($assignment, 2);

        $newTeacher = $this->makeTeacher($this->math, $this->makeStage(), 'أستاذ بديل');

        $this->asSupervisor()
            ->putJson("/api/supervisor/teacher-assignments/{$assignment->id}", [
                'teacher_id' => $newTeacher->id,
            ])
            ->assertOk();

        // الحصص بمكانها، وبس صاحبها تغيّر
        $this->assertSame(2, WeeklySchedule::count());
        $this->assertSame($newTeacher->id, $assignment->fresh()->teacher_id);
    }

    public function test_repeating_the_request_confirms_the_delete(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();

        $this->scheduleLesson($assignment, 1);
        $this->scheduleLesson($assignment, 2);

        $url = "/api/supervisor/teacher-assignments/{$assignment->id}";

        // الطلب الأول: تحذير
        $this->asSupervisor()->deleteJson($url)
            ->assertStatus(409)
            ->assertJsonPath('requires_confirmation', true);

        $this->assertSame(1, TeacherAssignment::count());

        // نفس الطلب مرة تانية: ينفّذ
        $this->asSupervisor()->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('data.deleted_lessons', 2);

        $this->assertSame(0, TeacherAssignment::count());

        // ما بتضل حصص يتيمة محجوزة بالجدول بلا مادة
        $this->assertSame(0, WeeklySchedule::count());
    }

    public function test_an_assignment_without_lessons_deletes_immediately(): void
    {
        // ما في شي رح يضيع، فما بدّو تأكيد
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();

        $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted_lessons', 0);

        $this->assertSame(0, TeacherAssignment::count());
    }

    public function test_the_confirmation_belongs_to_one_supervisor_only(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();
        $this->scheduleLesson($assignment, 1);

        $url = "/api/supervisor/teacher-assignments/{$assignment->id}";

        // موجّه أول بيشوف التحذير
        $this->asSupervisor()->deleteJson($url)->assertStatus(409);

        // موجّه تاني لازم يشوف التحذير هو كمان، مش يستفيد من تأكيد غيره
        $other = $this->makeSupervisor();

        $this->actingAs($other->user, 'sanctum')
            ->deleteJson($url)
            ->assertStatus(409);

        $this->assertSame(1, TeacherAssignment::count());
    }

    public function test_the_warning_mentions_repeating_the_request(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();
        $this->scheduleLesson($assignment, 1);

        $message = $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertStatus(409)
            ->json('message');

        $this->assertStringContainsString('أعد إرسال الطلب نفسه', $message);
        $this->assertStringNotContainsString('force', $message);
    }

    public function test_deleting_an_assignment_does_not_delete_the_grades(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();

        $student = $this->makeStudent($this->section, '10001');

        Grade::create([
            'student_id' => $student->id,
            'teacher_assignment_id' => $assignment->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'type' => 'exam',
            'semester' => 1,
            'value' => 90,
            'status' => 'draft',
        ]);

        $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertOk();

        // العلامة بتضل — التكليف كان مرجع لمين رصد، مش مالك العلامة
        $this->assertSame(1, Grade::count());

        $grade = Grade::first();
        $this->assertNull($grade->teacher_assignment_id);
        $this->assertSame($this->math->id, $grade->subject_id);
        $this->assertEquals(90, $grade->value);
    }

    public function test_a_shared_grade_book_survives_one_teachers_removal(): void
    {
        // معلمين لنفس المادة ونفس الشعبة، ودفتر علامات واحد
        $this->link()->assertCreated();
        $second = $this->makeTeacher($this->math, $this->makeStage(), 'أستاذ رياضيات ثاني');
        $this->link(['teacher_id' => $second->id])->assertCreated();

        $student = $this->makeStudent($this->section, '10001');
        $firstAssignment = TeacherAssignment::where('teacher_id', $this->teacher->id)->first();

        Grade::create([
            'student_id' => $student->id,
            'teacher_assignment_id' => $firstAssignment->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'type' => 'exam',
            'semester' => 1,
            'value' => 85,
            'status' => 'draft',
        ]);

        // نحذف تكليف المعلم الأول — المعلم التاني لسا بيدرّس المادة
        $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$firstAssignment->id}")
            ->assertOk();

        // علامات المادة ما بتضيع لأن معلم تاني لسا مسؤول عنها
        $this->assertSame(1, Grade::count());
        $this->assertEquals(85, Grade::first()->value);
    }

    public function test_deleting_an_assignment_keeps_the_submission_record(): void
    {
        $this->link()->assertCreated();
        $assignment = TeacherAssignment::first();

        GradeSubmission::create([
            'teacher_assignment_id' => $assignment->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'semester' => 1,
            'status' => 'approved',
        ]);

        $this->asSupervisor()
            ->deleteJson("/api/supervisor/teacher-assignments/{$assignment->id}")
            ->assertOk();

        $this->assertSame(1, GradeSubmission::count());
        $this->assertNull(GradeSubmission::first()->teacher_assignment_id);
    }
}
