<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Services\GradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private Student $student;
    private $mathAssignment;
    private $arabicAssignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();

        $stage = $this->makeStage();
        $math = $this->makeSubject('رياضيات');
        $arabic = $this->makeSubject('عربي');

        $section = $this->makeSection('A');
        $this->student = $this->makeStudent($section, '10001');

        $this->mathAssignment = $this->makeAssignment(
            $this->makeTeacher($math, $stage, 'Math Teacher'), $math, $section
        );
        $this->arabicAssignment = $this->makeAssignment(
            $this->makeTeacher($arabic, $stage, 'Arabic Teacher'), $arabic, $section
        );
    }

    private function actingAsSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    /** يرصد المكوّنات الثلاثة لمادة، ويعتمدها اختيارياً */
    private function grade($assignment, array $values, int $semester = 1, bool $approved = true): void
    {
        foreach ($values as $type => $value) {
            Grade::create([
                'student_id' => $this->student->id,
                'teacher_assignment_id' => $assignment->id,
                'subject_id' => $assignment->subject_id,
                'section_id' => $assignment->section_id,
                'type' => $type,
                'semester' => $semester,
                'value' => $value,
                'status' => 'approved',
            ]);
        }

        GradeSubmission::create([
            'teacher_assignment_id' => $assignment->id,
            'subject_id' => $assignment->subject_id,
            'section_id' => $assignment->section_id,
            'semester' => $semester,
            'status' => $approved ? 'approved' : 'submitted',
        ]);
    }

    private function fullMarks(): array
    {
        return ['participation' => 80, 'quiz' => 60, 'exam' => 90];
    }

    private function reportCard(int $semester = 1)
    {
        return $this->actingAsSupervisor()
            ->getJson("/api/supervisor/students/{$this->student->id}/report-card?semester={$semester}");
    }

    // ==================== الكشف ====================

    public function test_supervisor_can_pull_a_report_card(): void
    {
        $this->grade($this->mathAssignment, $this->fullMarks());

        $this->reportCard()
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.student.student_number', '10001')
            ->assertJsonPath('data.student.section_name', 'A');
    }

    public function test_the_weighted_total_is_correct(): void
    {
        // 80×20% + 60×30% + 90×50% = 16 + 18 + 45 = 79
        $this->grade($this->mathAssignment, $this->fullMarks());

        $response = $this->reportCard()->assertOk();

        $math = collect($response->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'رياضيات');

        $this->assertEquals(79.0, $math['total_value']);
        $this->assertTrue($math['passed']);          // passing_grade = 50
        $this->assertTrue($math['is_final']);
    }

    public function test_every_component_is_broken_down_with_its_weight(): void
    {
        $this->grade($this->mathAssignment, $this->fullMarks());

        $math = collect($this->reportCard()->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'رياضيات');

        $this->assertCount(3, $math['components']);

        $participation = collect($math['components'])->firstWhere('type', 'participation');
        $this->assertSame(20, $participation['weight']);
        $this->assertEquals(80.0, $participation['value']);
        $this->assertEquals(16.0, $participation['weighted_value']);
    }

    public function test_a_failing_subject_is_flagged(): void
    {
        // 20×20% + 20×30% + 20×50% = 20  → تحت علامة النجاح 50
        $this->grade($this->mathAssignment, ['participation' => 20, 'quiz' => 20, 'exam' => 20]);

        $math = collect($this->reportCard()->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'رياضيات');

        $this->assertEquals(20.0, $math['total_value']);
        $this->assertFalse($math['passed']);
    }

    // ==================== المواد الناقصة وغير المعتمدة ====================

    public function test_unapproved_subjects_appear_but_are_marked(): void
    {
        $this->grade($this->mathAssignment, $this->fullMarks(), approved: true);
        $this->grade($this->arabicAssignment, $this->fullMarks(), approved: false);

        $subjects = collect($this->reportCard()->json('data.semesters.0.subjects'));

        // المادة غير المعتمدة موجودة بالكشف — مش مخفية
        $this->assertCount(2, $subjects);

        $arabic = $subjects->firstWhere('subject', 'عربي');
        $this->assertFalse($arabic['is_final']);
        $this->assertSame('submitted', $arabic['status']);
    }

    public function test_the_average_ignores_unapproved_subjects(): void
    {
        $this->grade($this->mathAssignment, $this->fullMarks(), approved: true);           // 79
        $this->grade($this->arabicAssignment, ['participation' => 20, 'quiz' => 20, 'exam' => 20], approved: false);

        $summary = $this->reportCard()->json('data.semesters.0.summary');

        $this->assertEquals(79.0, $summary['average']);   // مش (79+20)/2
        $this->assertSame(1, $summary['counted_subjects']);
        $this->assertSame(1, $summary['pending_subjects']);
        $this->assertSame(2, $summary['total_subjects']);
        $this->assertFalse($summary['is_complete']);
    }

    public function test_the_average_uses_every_approved_subject(): void
    {
        $this->grade($this->mathAssignment, $this->fullMarks());                            // 79
        $this->grade($this->arabicAssignment, ['participation' => 20, 'quiz' => 20, 'exam' => 20]); // 20

        $summary = $this->reportCard()->json('data.semesters.0.summary');

        $this->assertEquals(49.5, $summary['average']);
        $this->assertSame(1, $summary['passed_subjects']);
        $this->assertSame(1, $summary['failed_subjects']);
        $this->assertTrue($summary['is_complete']);
    }

    public function test_a_subject_with_a_missing_component_has_no_total(): void
    {
        // بدون علامة الامتحان
        $this->grade($this->mathAssignment, ['participation' => 80, 'quiz' => 60]);

        $math = collect($this->reportCard()->json('data.semesters.0.subjects'))
            ->firstWhere('subject', 'رياضيات');

        $this->assertNull($math['total_value']);
        $this->assertNull($math['passed']);
        $this->assertFalse($math['is_complete']);
        $this->assertSame(1, $math['missing_components']);
    }

    public function test_a_subject_never_graded_still_appears(): void
    {
        $this->grade($this->mathAssignment, $this->fullMarks());

        $subjects = collect($this->reportCard()->json('data.semesters.0.subjects'));

        $arabic = $subjects->firstWhere('subject', 'عربي');
        $this->assertSame('draft', $arabic['status']);
        $this->assertSame(3, $arabic['missing_components']);
        $this->assertNull($arabic['total_value']);
    }

    // ==================== الفصول ====================

    public function test_both_semesters_are_returned_when_none_is_given(): void
    {
        $this->grade($this->mathAssignment, $this->fullMarks(), semester: 1);
        $this->grade($this->mathAssignment, ['participation' => 40, 'quiz' => 40, 'exam' => 40], semester: 2);

        $response = $this->actingAsSupervisor()
            ->getJson("/api/supervisor/students/{$this->student->id}/report-card")
            ->assertOk();

        $this->assertCount(2, $response->json('data.semesters'));
        $this->assertSame(1, $response->json('data.semesters.0.semester'));
        $this->assertSame(2, $response->json('data.semesters.1.semester'));
        $this->assertEquals(40.0, $response->json('data.semesters.1.summary.average'));
    }

    public function test_an_invalid_semester_is_rejected(): void
    {
        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/students/{$this->student->id}/report-card?semester=5")
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester');
    }

    // ==================== حالات الحافة والصلاحيات ====================

    public function test_a_student_without_a_section_is_rejected(): void
    {
        $this->student->update(['section_id' => null]);

        $this->reportCard()->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_non_supervisors_are_blocked(): void
    {
        $this->actingAs($this->makeUser('teacher', 'Nosy'), 'sanctum')
            ->getJson("/api/supervisor/students/{$this->student->id}/report-card?semester=1")
            ->assertForbidden();
    }

    // ==================== البحث عن طالب ====================

    public function test_students_can_be_searched_by_number(): void
    {
        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/students/search?q=10001')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.students.0.student_id', $this->student->id);
    }

    public function test_search_returns_nothing_for_an_unknown_student(): void
    {
        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/students/search?q=99999')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    // ==================== التدريس المشترك: دفتر واحد للمادة ====================

    public function test_two_teachers_of_the_same_subject_share_one_grade_book(): void
    {
        $math = $this->mathAssignment->subject;

        // معلم تاني لنفس المادة ونفس الشعبة
        $secondTeacher = $this->makeTeacher($math, $this->makeStage(), 'Second Math Teacher');
        $secondAssignment = $this->makeAssignment($secondTeacher, $math, $this->student->section);

        $this->grade($this->mathAssignment, $this->fullMarks());

        $subjects = collect($this->reportCard()->json('data.semesters.0.subjects'));

        // الرياضيات مرة وحدة بس — مش مرتين
        $this->assertCount(1, $subjects->where('subject', 'رياضيات'));

        // والمعلمَين الاثنين مذكورين على نفس الصف
        $mathRow = $subjects->firstWhere('subject', 'رياضيات');
        $this->assertCount(2, $mathRow['teachers']);
        $this->assertEqualsCanonicalizing(
            ['Math Teacher', 'Second Math Teacher'],
            $mathRow['teachers']
        );
        $this->assertEquals(79.0, $mathRow['total_value']);

        // ولا تنحسب مرتين بالمعدّل
        $summary = $this->reportCard()->json('data.semesters.0.summary');
        $this->assertSame(1, $summary['counted_subjects']);
        $this->assertEquals(79.0, $summary['average']);

        $this->assertNotNull($secondAssignment);
    }

    public function test_the_second_teacher_writes_into_the_same_book(): void
    {
        $math = $this->mathAssignment->subject;
        $secondTeacher = $this->makeTeacher($math, $this->makeStage(), 'Second Math Teacher');
        $secondAssignment = $this->makeAssignment($secondTeacher, $math, $this->student->section);

        $service = app(GradeService::class);

        // المعلم الأول يرصد المشاركة، والتاني يرصد الامتحان — نفس الدفتر
        $service->store($this->mathAssignment, [
            'student_id' => $this->student->id, 'type' => 'participation', 'semester' => 1, 'value' => 80,
        ]);
        $service->store($secondAssignment, [
            'student_id' => $this->student->id, 'type' => 'exam', 'semester' => 1, 'value' => 90,
        ]);

        // صفّان فقط، وكلاهما تحت نفس المادة
        $this->assertSame(2, Grade::where('subject_id', $math->id)->count());
        $this->assertSame(2, Grade::where('student_id', $this->student->id)->count());
    }

    public function test_the_second_teacher_overwrites_rather_than_duplicates(): void
    {
        $math = $this->mathAssignment->subject;
        $secondTeacher = $this->makeTeacher($math, $this->makeStage(), 'Second Math Teacher');
        $secondAssignment = $this->makeAssignment($secondTeacher, $math, $this->student->section);

        $service = app(GradeService::class);

        $service->store($this->mathAssignment, [
            'student_id' => $this->student->id, 'type' => 'exam', 'semester' => 1, 'value' => 60,
        ]);
        $service->store($secondAssignment, [
            'student_id' => $this->student->id, 'type' => 'exam', 'semester' => 1, 'value' => 90,
        ]);

        // علامة امتحان وحدة، آخر قيمة هي المعتمدة
        $exams = Grade::where('subject_id', $math->id)->where('type', 'exam')->get();
        $this->assertCount(1, $exams);
        $this->assertEquals(90, $exams->first()->value);
    }

    // ==================== اختبارات تراجع للباگات المصلَّحة ====================

    public function test_grade_submissions_can_record_who_approved_them(): void
    {
        // العمود approved_by كان ناقص من الجدول رغم إنو الكود بيكتبه
        $submission = GradeSubmission::create([
            'teacher_assignment_id' => $this->mathAssignment->id,
            'semester' => 1,
            'status' => 'approved',
            'approved_by' => $this->supervisor->user->id,
        ]);

        $this->assertDatabaseHas('grade_submissions', [
            'id' => $submission->id,
            'approved_by' => $this->supervisor->user->id,
        ]);
    }

    public function test_a_section_auto_submits_once_all_components_are_entered(): void
    {
        // كان بيدوّر على نوع 'study' وهو أصلاً مش موجود بالـ enum،
        // فما كان يكتمل أي كشف ولا يوصل للاعتماد أبداً
        $service = app(GradeService::class);

        foreach (['participation' => 80, 'quiz' => 60] as $type => $value) {
            Grade::create([
                'student_id' => $this->student->id,
                'teacher_assignment_id' => $this->mathAssignment->id,
                'subject_id' => $this->mathAssignment->subject_id,
                'section_id' => $this->mathAssignment->section_id,
                'type' => $type, 'semester' => 1, 'value' => $value, 'status' => 'draft',
            ]);
        }

        $this->assertFalse($service->isSectionComplete($this->mathAssignment, 1));

        Grade::create([
            'student_id' => $this->student->id,
            'teacher_assignment_id' => $this->mathAssignment->id,
            'subject_id' => $this->mathAssignment->subject_id,
            'section_id' => $this->mathAssignment->section_id,
            'type' => 'exam', 'semester' => 1, 'value' => 90, 'status' => 'draft',
        ]);

        $this->assertTrue($service->isSectionComplete($this->mathAssignment, 1));
        $this->assertTrue($service->autoSubmitIfComplete($this->mathAssignment, 1));

        $this->assertDatabaseHas('grade_submissions', [
            'teacher_assignment_id' => $this->mathAssignment->id,
            'semester' => 1,
            'status' => 'submitted',
        ]);
    }
}
