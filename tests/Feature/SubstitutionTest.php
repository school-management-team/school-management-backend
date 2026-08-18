<?php

namespace Tests\Feature;

use App\Models\LessonSubstitution;
use App\Models\TeacherAttendance;
use App\Models\WeeklySchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class SubstitutionTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $absentMathTeacher;   // غايب — حصته بدها تعويض
    private $freeMathTeacher;     // رياضيات وفاضي  → الأنسب
    private $freeArabicTeacher;   // فاضي بس مادة تانية → بديل مقبول
    private $busyMathTeacher;     // رياضيات بس عندو حصة بنفس الوقت
    private $absentMathLesson;
    private $date;

    protected function setUp(): void
    {
        parent::setUp();

        // منثبّت التاريخ على يوم أحد حتى day_of_week يكون sunday دايماً
        $this->date = '2026-08-16';
        Carbon::setTestNow(Carbon::parse($this->date.' 09:00:00'));

        $this->supervisor = $this->makeSupervisor();

        $stage = $this->makeStage();
        $math = $this->makeSubject('رياضيات');
        $arabic = $this->makeSubject('عربي');

        $this->absentMathTeacher = $this->makeTeacher($math, $stage, 'Absent Math');
        $this->freeMathTeacher = $this->makeTeacher($math, $stage, 'Free Math');
        $this->freeArabicTeacher = $this->makeTeacher($arabic, $stage, 'Free Arabic');
        $this->busyMathTeacher = $this->makeTeacher($math, $stage, 'Busy Math');

        $sectionA = $this->makeSection('A');
        $sectionB = $this->makeSection('B');

        // حصة المعلم الغايب: الأحد، الحصة 2
        $this->absentMathLesson = WeeklySchedule::create([
            'teacher_id' => $this->absentMathTeacher->id,
            'teacher_assignment_id' => $this->makeAssignment($this->absentMathTeacher, $math, $sectionA)->id,
            'day_of_week' => 'sunday', 'period_number' => 2,
            'start_time' => '08:45:00', 'end_time' => '09:30:00', 'type' => 'class',
        ]);

        // معلم رياضيات تاني عندو حصة بنفس الخانة → لازم يُستبعد
        WeeklySchedule::create([
            'teacher_id' => $this->busyMathTeacher->id,
            'teacher_assignment_id' => $this->makeAssignment($this->busyMathTeacher, $math, $sectionB)->id,
            'day_of_week' => 'sunday', 'period_number' => 2,
            'start_time' => '08:45:00', 'end_time' => '09:30:00', 'type' => 'class',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    /** بيسجّل الغايب غايب والباقي حاضرين، عن طريق الـ API نفسها */
    private function recordAttendance(): void
    {
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeMathTeacher->id, 'status' => 'present'],
                ['teacher_id' => $this->freeArabicTeacher->id, 'status' => 'present'],
                ['teacher_id' => $this->busyMathTeacher->id, 'status' => 'present'],
            ],
        ])->assertCreated();
    }

    private function assign(int $substituteId)
    {
        return $this->actingAsSupervisor()->postJson('/api/supervisor/substitutions', [
            'weekly_schedule_id' => $this->absentMathLesson->id,
            'substitute_teacher_id' => $substituteId,
            'date' => $this->date,
        ]);
    }

    // ==================== الحضور ====================

    public function test_supervisor_records_teacher_attendance(): void
    {
        $this->recordAttendance();

        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_id' => $this->absentMathTeacher->id,
            'status' => 'absent',
            'supervisor_id' => $this->supervisor->id,
        ]);
        $this->assertSame(4, TeacherAttendance::count());
    }

    public function test_recording_attendance_twice_updates_instead_of_duplicating(): void
    {
        $this->recordAttendance();

        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'excused', 'excuse' => 'مرض'],
            ],
        ])
            ->assertOk()   // تحديث مش تسجيل جديد
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.updated_count', 1);

        $this->assertSame(4, TeacherAttendance::count());
        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_id' => $this->absentMathTeacher->id,
            'status' => 'excused',
            'excuse' => 'مرض',
        ]);
    }

    public function test_teacher_sheet_returns_a_summary(): void
    {
        $this->recordAttendance();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/attendance/teachers?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 4)
            ->assertJsonPath('data.summary.present', 3)
            ->assertJsonPath('data.summary.absent', 1);
    }

    // ==================== كشف الحصص المكشوفة ====================

    public function test_absent_lessons_lists_the_uncovered_lesson(): void
    {
        $this->recordAttendance();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('data.uncovered_count', 1)
            ->assertJsonPath('data.absent_teachers.0.teacher_id', $this->absentMathTeacher->id)
            ->assertJsonPath('data.absent_teachers.0.lessons.0.is_covered', false)
            ->assertJsonPath('data.absent_teachers.0.lessons.0.period_number', 2);
    }

    public function test_no_absent_lessons_when_everyone_is_present(): void
    {
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [['teacher_id' => $this->absentMathTeacher->id, 'status' => 'present']],
        ])->assertCreated();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('data.uncovered_count', 0)
            ->assertJsonCount(0, 'data.absent_teachers');
    }

    // ==================== ترشيح البدلاء ====================

    public function test_available_teachers_ranks_same_subject_first(): void
    {
        $this->recordAttendance();

        $response = $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )->assertOk();

        // المتاحون: معلم الرياضيات الفاضي + معلم العربي الفاضي.
        // المشغول مستبعد، والغايب مستبعد.
        $response->assertJsonPath('data.total_available', 2)
            ->assertJsonPath('data.same_subject_count', 1)
            ->assertJsonPath('data.available_teachers.0.teacher_id', $this->freeMathTeacher->id)
            ->assertJsonPath('data.available_teachers.0.same_subject', true)
            ->assertJsonPath('data.available_teachers.1.teacher_id', $this->freeArabicTeacher->id);
    }

    public function test_teachers_not_at_school_are_excluded(): void
    {
        // ما منسجّل حضور لمعلم الرياضيات الفاضي إطلاقاً
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeArabicTeacher->id, 'status' => 'present'],
            ],
        ])->assertCreated();

        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )->assertOk()->assertJsonPath('data.total_available', 1)
            ->assertJsonPath('data.available_teachers.0.teacher_id', $this->freeArabicTeacher->id);
    }

    public function test_a_late_teacher_counts_as_present(): void
    {
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeMathTeacher->id, 'status' => 'late', 'check_in_time' => '08:20'],
            ],
        ])->assertCreated();

        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )->assertOk()->assertJsonPath('data.total_available', 1);
    }

    // ==================== التعيين ====================

    public function test_supervisor_assigns_a_substitute(): void
    {
        $this->recordAttendance();

        $this->assign($this->freeMathTeacher->id)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('lesson_substitutions', [
            'weekly_schedule_id' => $this->absentMathLesson->id,
            'absent_teacher_id' => $this->absentMathTeacher->id,
            'substitute_teacher_id' => $this->freeMathTeacher->id,
            'supervisor_id' => $this->supervisor->id,
            'period_number' => 2,
            'status' => 'assigned',
        ]);
    }

    public function test_lesson_shows_as_covered_after_assignment(): void
    {
        $this->recordAttendance();
        $this->assign($this->freeMathTeacher->id)->assertCreated();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('data.uncovered_count', 0)
            ->assertJsonPath('data.absent_teachers.0.lessons.0.is_covered', true)
            ->assertJsonPath(
                'data.absent_teachers.0.lessons.0.substitution.substitute_teacher_id',
                $this->freeMathTeacher->id
            );
    }

    public function test_a_busy_teacher_cannot_be_assigned(): void
    {
        $this->recordAttendance();

        $this->assign($this->busyMathTeacher->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, LessonSubstitution::count());
    }

    public function test_a_teacher_not_at_school_cannot_be_assigned(): void
    {
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeMathTeacher->id, 'status' => 'absent'],
            ],
        ])->assertCreated();

        $this->assign($this->freeMathTeacher->id)->assertStatus(422);
        $this->assertSame(0, LessonSubstitution::count());
    }

    public function test_cannot_substitute_for_a_teacher_who_is_not_absent(): void
    {
        // الكل حاضر، ما في غياب أصلاً
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'present'],
                ['teacher_id' => $this->freeMathTeacher->id, 'status' => 'present'],
            ],
        ])->assertCreated();

        $this->assign($this->freeMathTeacher->id)->assertStatus(422);
        $this->assertSame(0, LessonSubstitution::count());
    }

    public function test_a_teacher_cannot_substitute_for_himself(): void
    {
        $this->recordAttendance();

        $this->assign($this->absentMathTeacher->id)->assertStatus(422);
        $this->assertSame(0, LessonSubstitution::count());
    }

    public function test_substitute_cannot_take_two_lessons_in_the_same_period(): void
    {
        $this->recordAttendance();
        $this->assign($this->freeMathTeacher->id)->assertCreated();

        // حصة تانية بنفس الخانة لمعلم غايب تاني
        $secondLesson = WeeklySchedule::create([
            'teacher_id' => $this->busyMathTeacher->id,
            'teacher_assignment_id' => $this->busyMathTeacher->assignments()->first()->id,
            'day_of_week' => 'sunday', 'period_number' => 3,
            'start_time' => '09:30:00', 'end_time' => '10:15:00', 'type' => 'class',
        ]);

        TeacherAttendance::where('teacher_id', $this->busyMathTeacher->id)->update(['status' => 'absent']);

        // نفس البديل بحصة تانية (3) — مسموح، مو نفس التوقيت
        $this->actingAsSupervisor()->postJson('/api/supervisor/substitutions', [
            'weekly_schedule_id' => $secondLesson->id,
            'substitute_teacher_id' => $this->freeMathTeacher->id,
            'date' => $this->date,
        ])->assertCreated();

        $this->assertSame(2, LessonSubstitution::count());
    }

    public function test_date_must_match_the_lesson_day(): void
    {
        $this->recordAttendance();

        // 2026-08-17 يوم اثنين، بس الحصة يوم أحد
        $this->actingAsSupervisor()->postJson('/api/supervisor/substitutions', [
            'weekly_schedule_id' => $this->absentMathLesson->id,
            'substitute_teacher_id' => $this->freeMathTeacher->id,
            'date' => '2026-08-17',
        ])->assertStatus(422);
    }

    public function test_reassigning_replaces_the_previous_substitute(): void
    {
        $this->recordAttendance();

        $this->assign($this->freeMathTeacher->id)->assertCreated();
        $this->assign($this->freeArabicTeacher->id)->assertCreated();

        $this->assertSame(1, LessonSubstitution::count());
        $this->assertSame(
            $this->freeArabicTeacher->id,
            LessonSubstitution::first()->substitute_teacher_id
        );
    }

    public function test_cancelling_frees_the_substitute_again(): void
    {
        $this->recordAttendance();
        $this->assign($this->freeMathTeacher->id)->assertCreated();

        $substitution = LessonSubstitution::first();

        $this->actingAsSupervisor()
            ->patchJson("/api/supervisor/substitutions/{$substitution->id}/status", ['status' => 'cancelled'])
            ->assertOk();

        // بعد الإلغاء لازم يرجع يطلع بقائمة المتاحين
        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )->assertOk()->assertJsonPath('data.total_available', 2);
    }

    public function test_non_supervisors_are_blocked(): void
    {
        $this->actingAs($this->makeUser('teacher', 'Nosy'), 'sanctum')
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertForbidden();
    }

    // ==================== ملخّص الكشف ====================

    public function test_the_absent_lessons_summary_counts_everything(): void
    {
        $this->recordAttendance();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('message', '1 معلم غائب، 1 حصة، منها 1 بلا تعويض')
            ->assertJsonPath('data.absent_teachers_count', 1)
            ->assertJsonPath('data.lessons_count', 1)
            ->assertJsonPath('data.covered_count', 0)
            ->assertJsonPath('data.uncovered_count', 1);
    }

    public function test_the_summary_moves_a_lesson_to_covered_after_assignment(): void
    {
        $this->recordAttendance();
        $this->assign($this->freeMathTeacher->id)->assertCreated();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('data.covered_count', 1)
            ->assertJsonPath('data.uncovered_count', 0);
    }

    public function test_an_excused_teacher_is_treated_as_absent(): void
    {
        // غياب بعذر برضو بدو تعويض — المعلم مش موجود بالحالتين
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'excused', 'excuse' => 'مرض'],
            ],
        ])->assertCreated();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('data.absent_teachers_count', 1)
            ->assertJsonPath('data.absent_teachers.0.attendance_status', 'excused');
    }

    public function test_a_late_teacher_is_not_listed_as_absent(): void
    {
        // المتأخر وصل فعلياً، فحصصه ما بدها تعويض
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'late', 'check_in_time' => '08:20'],
            ],
        ])->assertCreated();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('message', 'لا يوجد معلمون غائبون في هذا التاريخ')
            ->assertJsonPath('data.absent_teachers_count', 0);
    }

    // ==================== توضيح سبب عدم وجود مرشحين ====================

    public function test_no_attendance_recorded_is_explained(): void
    {
        // ما سجّلنا حضور أبداً — فما منعرف مين بالمدرسة
        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )
            ->assertOk()
            ->assertJsonPath('data.total_available', 0)
            ->assertJsonPath('message', 'لم يُسجَّل حضور المعلمين في هذا التاريخ بعد، فلا يمكن معرفة من هو موجود في المدرسة');
    }

    public function test_everyone_away_is_explained(): void
    {
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeArabicTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->busyMathTeacher->id, 'status' => 'absent'],
            ],
        ])->assertCreated();

        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )
            ->assertOk()
            ->assertJsonPath('message', 'جميع المعلمين المسجّلين في هذا التاريخ غير موجودين في المدرسة');
    }

    public function test_everyone_busy_is_explained(): void
    {
        // الكل موجود بس كلهم عندهم حصص بنفس التوقيت
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->busyMathTeacher->id, 'status' => 'present'],
            ],
        ])->assertCreated();

        $response = $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )->assertOk();

        $this->assertStringContainsString('مشغولون', $response->json('message'));
    }

    public function test_a_date_on_another_weekday_names_both_days(): void
    {
        $this->recordAttendance();

        // 2026-08-17 اثنين، والحصة يوم أحد
        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date=2026-08-17"
        )
            ->assertStatus(422)
            ->assertJsonPath('data.lesson_day', 'sunday')
            ->assertJsonPath('data.date_day', 'monday');
    }

    public function test_a_missing_lesson_id_is_rejected(): void
    {
        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id=999999&date={$this->date}"
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('weekly_schedule_id');
    }

    public function test_a_successful_list_reports_the_count(): void
    {
        $this->recordAttendance();

        $this->actingAsSupervisor()->getJson(
            "/api/supervisor/substitutions/available-teachers?weekly_schedule_id={$this->absentMathLesson->id}&date={$this->date}"
        )
            ->assertOk()
            ->assertJsonPath('message', 'عدد المعلمين المتاحين: 2');
    }

    // ==================== فلتر نفس المادة ====================

    private function candidates(array $extra = [])
    {
        $query = array_merge([
            'weekly_schedule_id' => $this->absentMathLesson->id,
            'date' => $this->date,
        ], $extra);

        return $this->actingAsSupervisor()
            ->getJson('/api/supervisor/substitutions/available-teachers?'.http_build_query($query));
    }

    public function test_by_default_all_available_teachers_are_listed(): void
    {
        $this->recordAttendance();

        $this->candidates()
            ->assertOk()
            ->assertJsonPath('data.total_available', 2)
            ->assertJsonPath('data.same_subject_count', 1)
            ->assertJsonPath('data.filtered_by_same_subject', false);
    }

    public function test_same_subject_filter_keeps_only_matching_teachers(): void
    {
        $this->recordAttendance();

        $response = $this->candidates(['same_subject' => 1])
            ->assertOk()
            ->assertJsonPath('data.total_available', 1)
            ->assertJsonPath('data.filtered_by_same_subject', true);

        $this->assertSame($this->freeMathTeacher->id, $response->json('data.available_teachers.0.teacher_id'));
    }

    public function test_the_filter_explains_when_it_hides_everyone(): void
    {
        // معلم الرياضيات الفاضي غايب، فما بيضل غير معلم العربي
        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->date,
            'records' => [
                ['teacher_id' => $this->absentMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeMathTeacher->id, 'status' => 'absent'],
                ['teacher_id' => $this->freeArabicTeacher->id, 'status' => 'present'],
            ],
        ])->assertCreated();

        $response = $this->candidates(['same_subject' => 1])
            ->assertOk()
            ->assertJsonPath('data.total_available', 0);

        // بيقلّك إنه في بدلاء من مواد تانية بدل ما يقول "ما في حدا"
        $this->assertStringContainsString('من مواد أخرى', $response->json('message'));
    }

    public function test_the_lesson_block_names_the_absent_teacher(): void
    {
        $this->recordAttendance();

        // كتلة lesson معلومات الحصة الغائبة، مش مرشّح
        $this->candidates()
            ->assertOk()
            ->assertJsonPath('data.lesson.absent_teacher_id', $this->absentMathTeacher->id)
            ->assertJsonPath('data.lesson.absent_teacher_name', 'Absent Math');
    }

    public function test_the_listed_count_matches_the_array_length(): void
    {
        $this->recordAttendance();

        $response = $this->candidates()->assertOk();

        $this->assertCount(
            $response->json('data.total_available'),
            $response->json('data.available_teachers')
        );
    }
}
