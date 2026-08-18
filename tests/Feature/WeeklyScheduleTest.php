<?php

namespace Tests\Feature;

use App\Models\Stage;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class WeeklyScheduleTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $sectionA;
    private $sectionB;
    private $mathAssignmentA;
    private $arabicAssignmentA;
    private $mathAssignmentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();

        $stage = $this->makeStage();
        $math = $this->makeSubject('رياضيات');
        $arabic = $this->makeSubject('عربي');

        $mathTeacher = $this->makeTeacher($math, $stage, 'Math Teacher');
        $arabicTeacher = $this->makeTeacher($arabic, $stage, 'Arabic Teacher');

        $this->sectionA = $this->makeSection('A');
        $this->sectionB = $this->makeSection('B');

        $this->mathAssignmentA = $this->makeAssignment($mathTeacher, $math, $this->sectionA);
        $this->arabicAssignmentA = $this->makeAssignment($arabicTeacher, $arabic, $this->sectionA);
        $this->mathAssignmentB = $this->makeAssignment($mathTeacher, $math, $this->sectionB);
    }

    private function actingAsSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function addLesson(int $assignmentId, string $day = 'sunday', int $period = 1)
    {
        return $this->actingAsSupervisor()->postJson('/api/supervisor/schedule', [
            'teacher_assignment_id' => $assignmentId,
            'day_of_week' => $day,
            'period_number' => $period,
        ]);
    }

    public function test_supervisor_can_add_a_lesson(): void
    {
        $this->addLesson($this->mathAssignmentA->id)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('weekly_schedules', [
            'teacher_assignment_id' => $this->mathAssignmentA->id,
            'section_id' => $this->sectionA->id,
            'day_of_week' => 'sunday',
            'period_number' => 1,
            'start_time' => '08:00:00',
        ]);
    }

    public function test_section_id_is_derived_from_the_assignment(): void
    {
        $this->addLesson($this->mathAssignmentA->id)->assertCreated();

        // ما بعتنا section_id بالريكوست إطلاقاً — لازم ينسحب من التكليف
        $this->assertSame(
            $this->sectionA->id,
            WeeklySchedule::first()->section_id
        );
    }

    public function test_a_teacher_cannot_be_in_two_sections_at_once(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        // نفس معلم الرياضيات، شعبة تانية، نفس اليوم ونفس الحصة
        $this->addLesson($this->mathAssignmentB->id, 'sunday', 1)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.conflicts.0.type', 'teacher_busy');

        $this->assertSame(1, WeeklySchedule::count());
    }

    public function test_a_section_cannot_have_two_lessons_at_once(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        // نفس الشعبة، معلم ومادة مختلفين، نفس الخانة
        $this->addLesson($this->arabicAssignmentA->id, 'sunday', 1)
            ->assertStatus(422)
            ->assertJsonPath('data.conflicts.0.type', 'section_busy');

        $this->assertSame(1, WeeklySchedule::count());
    }

    public function test_the_same_section_can_be_scheduled_in_a_different_period(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();
        $this->addLesson($this->arabicAssignmentA->id, 'sunday', 2)->assertCreated();

        $this->assertSame(2, WeeklySchedule::count());
    }

    public function test_break_periods_are_rejected(): void
    {
        // الحصة رقم 4 معرّفة كاستراحة بالكونفيغ
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 4)->assertStatus(422);

        $this->assertSame(0, WeeklySchedule::count());
    }

    public function test_unknown_period_numbers_are_rejected(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 99)->assertStatus(422);
    }

    public function test_days_outside_the_school_week_are_rejected(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'friday', 1)
            ->assertStatus(422)
            ->assertJsonValidationErrors('day_of_week');
    }

    public function test_bulk_build_saves_nothing_when_any_row_conflicts(): void
    {
        $response = $this->actingAsSupervisor()->postJson('/api/supervisor/schedule/bulk', [
            'lessons' => [
                ['teacher_assignment_id' => $this->mathAssignmentA->id, 'day_of_week' => 'monday', 'period_number' => 1],
                ['teacher_assignment_id' => $this->arabicAssignmentA->id, 'day_of_week' => 'monday', 'period_number' => 2],
                // هاي بتتعارض مع أول وحدة (نفس الشعبة نفس الخانة)
                ['teacher_assignment_id' => $this->arabicAssignmentA->id, 'day_of_week' => 'monday', 'period_number' => 1],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('data.errors.0.index', 2);

        // الكل أو لا شي — حتى الصفين السليمين ما انحفظوا
        $this->assertSame(0, WeeklySchedule::count());
    }

    public function test_bulk_build_saves_a_valid_timetable(): void
    {
        $this->actingAsSupervisor()->postJson('/api/supervisor/schedule/bulk', [
            'lessons' => [
                ['teacher_assignment_id' => $this->mathAssignmentA->id, 'day_of_week' => 'monday', 'period_number' => 1],
                ['teacher_assignment_id' => $this->arabicAssignmentA->id, 'day_of_week' => 'monday', 'period_number' => 2],
                ['teacher_assignment_id' => $this->mathAssignmentB->id, 'day_of_week' => 'monday', 'period_number' => 3],
            ],
        ])->assertCreated()->assertJsonPath('data.created_count', 3);

        $this->assertSame(3, WeeklySchedule::count());
    }

    public function test_moving_a_lesson_into_an_occupied_slot_is_rejected(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();
        $this->addLesson($this->arabicAssignmentA->id, 'sunday', 2)->assertCreated();

        $lesson = WeeklySchedule::where('period_number', 2)->first();

        $this->actingAsSupervisor()
            ->putJson("/api/supervisor/schedule/{$lesson->id}", ['period_number' => 1])
            ->assertStatus(422);

        $this->assertSame(2, WeeklySchedule::find($lesson->id)->period_number);
    }

    public function test_a_lesson_can_move_to_a_free_slot(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();
        $lesson = WeeklySchedule::first();

        $this->actingAsSupervisor()
            ->putJson("/api/supervisor/schedule/{$lesson->id}", ['period_number' => 3])
            ->assertOk();

        $lesson->refresh();
        $this->assertSame(3, $lesson->period_number);
        $this->assertSame('09:30:00', $lesson->start_time);
    }

    public function test_section_schedule_returns_a_full_grid(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/schedule/section/{$this->sectionA->id}")
            ->assertOk()
            ->assertJsonPath('data.filled_slots', 1)
            ->assertJsonCount(5, 'data.schedule')          // 5 أيام دوام
            ->assertJsonCount(8, 'data.schedule.sunday')   // 8 خانات باليوم
            ->assertJsonPath('data.schedule.sunday.0.subject', 'رياضيات')
            ->assertJsonPath('data.schedule.sunday.3.type', 'break');
    }

    public function test_a_section_with_no_schedule_says_so(): void
    {
        // الشبكة بترجع كاملة بخانات فاضية — لازم يوضّح إنه ما في جدول
        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/schedule/section/{$this->sectionB->id}")
            ->assertOk()
            ->assertJsonPath('message', 'لا يوجد جدول مسجّل لهذه الشعبة بعد')
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.filled_slots', 0)
            ->assertJsonPath('data.empty_slots', 35);   // 7 حصص × 5 أيام
    }

    public function test_a_section_with_lessons_reports_the_counts(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();
        $this->addLesson($this->arabicAssignmentA->id, 'sunday', 2)->assertCreated();

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/schedule/section/{$this->sectionA->id}")
            ->assertOk()
            ->assertJsonPath('data.is_empty', false)
            ->assertJsonPath('data.filled_slots', 2)
            ->assertJsonPath('data.total_slots', 35)
            ->assertJsonPath('data.empty_slots', 33);
    }

    public function test_an_unknown_section_id_returns_not_found(): void
    {
        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/schedule/section/999999')
            ->assertNotFound();
    }

    public function test_an_unknown_teacher_id_returns_not_found(): void
    {
        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/schedule/teacher/999999')
            ->assertNotFound();
    }

    public function test_a_teacher_with_no_lessons_says_so(): void
    {
        $idleTeacher = $this->makeTeacher($this->makeSubject('علوم'), $this->makeStage(), 'معلم بلا نصاب');

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/schedule/teacher/{$idleTeacher->id}")
            ->assertOk()
            ->assertJsonPath('message', 'لا توجد حصص مسندة لهذا المعلم بعد')
            ->assertJsonPath('data.is_empty', true)
            ->assertJsonPath('data.free_slots', 35);
    }

    public function test_free_teachers_excludes_busy_ones(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        $response = $this->actingAsSupervisor()->getJson(
            '/api/supervisor/schedule/free-teachers?day_of_week=sunday&period_number=1'
        )->assertOk();

        // معلم الرياضيات صار مشغول، بيضل معلم العربي بس
        $response->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.free_teachers.0.subject', 'عربي');
    }

    public function test_non_supervisors_are_blocked(): void
    {
        $teacherUser = $this->makeUser('teacher', 'Random Teacher');

        $this->actingAs($teacherUser, 'sanctum')
            ->postJson('/api/supervisor/schedule', [
                'teacher_assignment_id' => $this->mathAssignmentA->id,
                'day_of_week' => 'sunday',
                'period_number' => 1,
            ])
            ->assertForbidden();
    }
}
