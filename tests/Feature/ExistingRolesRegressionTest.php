<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * اختبارات تراجع لنقاط المعلم والأدمن الموجودة من قبل.
 * غيّرنا GradeService من تحتها (مفتاح دفتر العلامات + اسم نوع العلامة)،
 * فلازم نتأكد إنها لساتها شغّالة عبر الـ API الحقيقية مش بس عبر السيرفس.
 */
class ExistingRolesRegressionTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private Teacher $mathTeacher;
    private $mathAssignment;
    private Subject $math;
    private Student $studentA;
    private Student $studentB;
    private $section;

    protected function setUp(): void
    {
        parent::setUp();

        $stage = $this->makeStage();
        $this->math = $this->makeSubject('رياضيات');
        $this->section = $this->makeSection('A');

        $this->studentA = $this->makeStudent($this->section, '10001');
        $this->studentB = $this->makeStudent($this->section, '10002');

        $this->mathTeacher = $this->makeTeacher($this->math, $stage, 'Math Teacher');
        $this->mathAssignment = $this->makeAssignment($this->mathTeacher, $this->math, $this->section);
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->mathTeacher->user, 'sanctum');
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->makeUser('admin', 'Admin'), 'sanctum');
    }

    // ==================== نقاط المعلم ====================

    public function test_teacher_records_a_single_grade_through_the_api(): void
    {
        $this->asTeacher()->postJson('/api/teacher/grades', [
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'student_id' => $this->studentA->id,
            'type' => 'quiz',
            'semester' => 1,
            'value' => 75,
        ])->assertCreated();

        // العلامة لازم تنكتب مع subject_id و section_id (مفتاح الدفتر الجديد)
        $this->assertDatabaseHas('grades', [
            'student_id' => $this->studentA->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'type' => 'quiz',
            'value' => 75,
        ]);
    }

    public function test_the_old_study_type_is_no_longer_accepted(): void
    {
        // 'study' كان بالفاليديشن بس ما كان موجود بالـ enum إطلاقاً
        $this->asTeacher()->postJson('/api/teacher/grades', [
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'student_id' => $this->studentA->id,
            'type' => 'study',
            'semester' => 1,
            'value' => 75,
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_a_teacher_cannot_grade_a_subject_he_does_not_teach(): void
    {
        $other = $this->makeSubject('كيمياء');

        $this->asTeacher()->postJson('/api/teacher/grades', [
            'subject_id' => $other->id,
            'section_id' => $this->section->id,
            'student_id' => $this->studentA->id,
            'type' => 'quiz',
            'semester' => 1,
            'value' => 75,
        ])->assertForbidden();
    }

    public function test_teacher_opens_the_bulk_grading_sheet(): void
    {
        $this->asTeacher()
            ->getJson("/api/teacher/sections/{$this->mathAssignment->id}/students?type=quiz&semester=1")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_teacher_saves_bulk_grades_and_the_section_auto_submits(): void
    {
        // منرصد المكوّنات التلاتة لكل الطلاب — لازم يرتفع الكشف تلقائياً
        foreach (['participation', 'quiz', 'exam'] as $type) {
            $this->asTeacher()->postJson("/api/teacher/sections/{$this->mathAssignment->id}/grades", [
                'type' => $type,
                'semester' => 1,
                'grades' => [
                    ['student_id' => $this->studentA->id, 'value' => 80],
                    ['student_id' => $this->studentB->id, 'value' => 60],
                ],
            ])->assertOk();
        }

        $this->assertSame(6, Grade::count());

        // هذا يثبت إن إصلاح study→quiz اشتغل عبر الـ API مش بس بالسيرفس
        $this->assertDatabaseHas('grade_submissions', [
            'section_id' => $this->section->id,
            'subject_id' => $this->math->id,
            'semester' => 1,
            'status' => 'submitted',
        ]);
    }

    public function test_grades_stay_editable_until_the_admin_approves(): void
    {
        // الرفع التلقائي بيصير لما تكتمل المكوّنات، بس القفل بيصير بالاعتماد
        foreach (['participation', 'quiz', 'exam'] as $type) {
            $this->asTeacher()->postJson("/api/teacher/sections/{$this->mathAssignment->id}/grades", [
                'type' => $type,
                'semester' => 1,
                'grades' => [
                    ['student_id' => $this->studentA->id, 'value' => 80],
                    ['student_id' => $this->studentB->id, 'value' => 60],
                ],
            ])->assertOk();
        }

        $this->assertDatabaseHas('grade_submissions', [
            'section_id' => $this->section->id,
            'subject_id' => $this->math->id,
            'status' => 'submitted',
        ]);

        // مرفوع بس مش معتمد → التعديل لسا مسموح
        $this->asTeacher()->postJson('/api/teacher/grades', [
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'student_id' => $this->studentA->id,
            'type' => 'quiz',
            'semester' => 1,
            'value' => 99,
        ])->assertCreated();
    }

    public function test_grades_are_locked_after_approval(): void
    {
        foreach (['participation', 'quiz', 'exam'] as $type) {
            $this->asTeacher()->postJson("/api/teacher/sections/{$this->mathAssignment->id}/grades", [
                'type' => $type,
                'semester' => 1,
                'grades' => [
                    ['student_id' => $this->studentA->id, 'value' => 80],
                    ['student_id' => $this->studentB->id, 'value' => 60],
                ],
            ])->assertOk();
        }

        $submission = GradeSubmission::where('subject_id', $this->math->id)->first();

        $this->asAdmin()
            ->postJson("/api/admin/grade-submissions/{$submission->id}/approve")
            ->assertOk();

        // بعد الاعتماد ما بيقدر يعدّل
        $this->asTeacher()->postJson('/api/teacher/grades', [
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'student_id' => $this->studentA->id,
            'type' => 'quiz',
            'semester' => 1,
            'value' => 99,
        ])->assertStatus(400);
    }

    public function test_teacher_daily_and_weekly_schedule_still_work(): void
    {
        $this->asTeacher()->getJson('/api/teacher/schedule/weekly')->assertOk();
        $this->asTeacher()->getJson('/api/teacher/schedule/daily')->assertOk();
    }

    // ==================== نقاط الأدمن ====================

    public function test_admin_report_card_shows_an_approved_subject_once(): void
    {
        // معلم تاني لنفس المادة ونفس الشعبة — هون كان الكشف بيكرّر المادة
        $secondTeacher = $this->makeTeacher($this->math, $this->makeStage(), 'Second Math');
        $this->makeAssignment($secondTeacher, $this->math, $this->section);

        foreach (['participation' => 80, 'quiz' => 60, 'exam' => 90] as $type => $value) {
            Grade::create([
                'student_id' => $this->studentA->id,
                'teacher_assignment_id' => $this->mathAssignment->id,
                'subject_id' => $this->math->id,
                'section_id' => $this->section->id,
                'type' => $type,
                'semester' => 1,
                'value' => $value,
                'status' => 'approved',
            ]);
        }

        GradeSubmission::create([
            'teacher_assignment_id' => $this->mathAssignment->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'semester' => 1,
            'status' => 'approved',
        ]);

        $report = $this->asAdmin()
            ->getJson("/api/admin/students/{$this->studentA->id}/report-card?semester=1")
            ->assertOk()
            ->json('data');

        // مرة وحدة بس رغم وجود معلمين للمادة
        $this->assertCount(1, $report);
        $this->assertSame('رياضيات', $report[0]['subject']);
        $this->assertEquals(79, $report[0]['total_value']);
        $this->assertTrue($report[0]['passed']);
    }

    public function test_admin_report_card_hides_unapproved_subjects(): void
    {
        foreach (['participation' => 80, 'quiz' => 60, 'exam' => 90] as $type => $value) {
            Grade::create([
                'student_id' => $this->studentA->id,
                'teacher_assignment_id' => $this->mathAssignment->id,
                'subject_id' => $this->math->id,
                'section_id' => $this->section->id,
                'type' => $type,
                'semester' => 1,
                'value' => $value,
                'status' => 'draft',
            ]);
        }

        // مرفوع بس مش معتمد
        GradeSubmission::create([
            'teacher_assignment_id' => $this->mathAssignment->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'semester' => 1,
            'status' => 'submitted',
        ]);

        $this->asAdmin()
            ->getJson("/api/admin/students/{$this->studentA->id}/report-card?semester=1")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_approves_a_submission_and_records_the_approver(): void
    {
        foreach (['participation' => 80, 'quiz' => 60, 'exam' => 90] as $type => $value) {
            foreach ([$this->studentA, $this->studentB] as $student) {
                Grade::create([
                    'student_id' => $student->id,
                    'teacher_assignment_id' => $this->mathAssignment->id,
                    'subject_id' => $this->math->id,
                    'section_id' => $this->section->id,
                    'type' => $type,
                    'semester' => 1,
                    'value' => $value,
                    'status' => 'draft',
                ]);
            }
        }

        $submission = GradeSubmission::create([
            'teacher_assignment_id' => $this->mathAssignment->id,
            'subject_id' => $this->math->id,
            'section_id' => $this->section->id,
            'semester' => 1,
            'status' => 'submitted',
        ]);

        $admin = $this->makeUser('admin', 'Admin');

        // كان بيوقع بخطأ SQL لأن عمود approved_by ما كان موجود
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/grade-submissions/{$submission->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('grade_submissions', [
            'id' => $submission->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    public function test_admin_pending_submissions_list_works(): void
    {
        $this->asAdmin()->getJson('/api/admin/grades/pending')->assertOk();
    }

    public function test_admin_attendance_reports_still_work(): void
    {
        // هالتقارير بتعمل join على attendance، وإحنا غيّرنا enum الحالة
        $range = 'from=2026-08-01&to=2026-08-31';

        $this->asAdmin()->getJson('/api/admin/reports/attendance-rate?'.$range)->assertOk();
        $this->asAdmin()->getJson('/api/admin/reports/most-absent?'.$range)->assertOk();
    }

    public function test_admin_daily_attendance_shows_the_new_early_leave_status(): void
    {
        $supervisor = $this->makeSupervisor();

        $this->actingAs($supervisor->user, 'sanctum')
            ->postJson('/api/supervisor/attendance/students', [
                'section_id' => $this->section->id,
                'date' => '2026-08-16',
                'records' => [
                    ['student_id' => $this->studentA->id, 'status' => 'early_leave', 'left_at' => '11:30'],
                    ['student_id' => $this->studentB->id, 'status' => 'present'],
                ],
            ])->assertCreated();

        $records = $this->asAdmin()
            ->getJson('/api/admin/attendance/day?section_id='.$this->section->id.'&date=2026-08-16')
            ->assertOk()
            ->json('data.records');

        $this->assertCount(2, $records);

        $earlyLeave = null;

        foreach ($records as $record) {
            if ($record['status'] === 'early_leave') {
                $earlyLeave = $record;
            }
        }

        $this->assertNotNull($earlyLeave, 'حالة الخروج المبكر ما ظهرت بتقرير الأدمن');
        $this->assertSame('11:30:00', $earlyLeave['left_at']);
    }
}
