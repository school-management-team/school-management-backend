<?php

namespace Tests\Feature;

use App\Models\Stage;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class WeeklyScheduleTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private $sectionA;
    private $sectionB;
    private $math;
    private $mathAssignmentA;
    private $arabicAssignmentA;
    private $mathAssignmentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();

        $stage = $this->makeStage();
        $math = $this->makeSubject('رياضيات');
        $this->math = $math;
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

    public function test_free_teachers_explains_the_count_it_returned(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        $data = $this->actingAsSupervisor()->getJson(
            '/api/supervisor/schedule/free-teachers?day_of_week=sunday&period_number=1'
        )->assertOk()
            ->assertJsonPath('data.day_label', 'الأحد')
            ->assertJsonPath('data.busy_count', 1)
            ->json();

        // الرسالة بتقول من أصل كم، وكم فاضي، وبأي خانة — بدون ما يعدّ المستخدم
        $this->assertStringContainsString('الأحد', $data['message']);
        $this->assertStringContainsString((string) $data['data']['checked_count'], $data['message']);
    }

    public function test_free_teachers_says_so_when_everyone_is_busy(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        // نفلتر على مادة الرياضيات وحدها — ومعلمها مشغول بهالخانة
        $this->actingAsSupervisor()->getJson(
            '/api/supervisor/schedule/free-teachers?day_of_week=sunday&period_number=1'
            .'&subject_id='.$this->math->id
        )->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('message', 'كل معلمي هذه المادة (1) عندهم حصة يوم الأحد بالحصة 1');
    }

    public function test_free_teachers_reports_the_period_time(): void
    {
        $this->actingAsSupervisor()->getJson(
            '/api/supervisor/schedule/free-teachers?day_of_week=monday&period_number=2'
        )->assertOk()
            ->assertJsonPath('data.starts_at', config('school.periods.2.start'))
            ->assertJsonPath('data.ends_at', config('school.periods.2.end'));
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

    // ==================== المادة لازم تكون مقررة بالمرحلة ====================

    /** تكليف مخالف: مادة مش مقررة بمرحلة الصف */
    private function makeOffStageAssignment()
    {
        $history = $this->makeSubject('التاريخ');
        $teacher = $this->makeTeacher($history, $this->makeStage(), 'أستاذ التاريخ');
        $assignment = $this->makeAssignment($teacher, $history, $this->sectionA);

        // فكّ الربط بالآخر — makeStage بيعيد ربط كل المواد، فلازم يكون هو الأخير.
        // ننشئ التكليف بالموديل مباشرة لأن الـ API بترفضه، وهاد بالضبط
        // سيناريو التكاليف القديمة اللي انخلقت قبل ما تنضاف القاعدة.
        $history->stages()->sync([]);

        return $assignment;
    }

    public function test_a_lesson_for_a_subject_not_taught_in_the_stage_is_rejected(): void
    {
        $assignment = $this->makeOffStageAssignment();

        $response = $this->addLesson($assignment->id, 'sunday', 3)
            ->assertStatus(422)
            ->assertJsonPath('data.assignment_id', $assignment->id)
            ->assertJsonPath('data.subject', 'التاريخ');

        $this->assertStringContainsString('غير مقررة في مرحلة', $response->json('message'));
        $this->assertSame(0, WeeklySchedule::count());
    }

    public function test_a_valid_subject_is_still_accepted(): void
    {
        // الرياضيات مقررة بالابتدائي — ما في سبب للرفض
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 3)->assertCreated();

        $this->assertSame(1, WeeklySchedule::count());
    }

    public function test_the_bulk_builder_rejects_the_offending_row_only(): void
    {
        $bad = $this->makeOffStageAssignment();

        $response = $this->actingAsSupervisor()->postJson('/api/supervisor/schedule/bulk', [
            'lessons' => [
                ['teacher_assignment_id' => $this->mathAssignmentA->id, 'day_of_week' => 'monday', 'period_number' => 1],
                ['teacher_assignment_id' => $bad->id, 'day_of_week' => 'monday', 'period_number' => 2],
            ],
        ])->assertStatus(422);

        // بيشاور على العنصر المخالف بالضبط
        $this->assertSame(1, $response->json('data.errors.0.index'));
        $this->assertSame($bad->id, $response->json('data.errors.0.assignment_id'));

        // كلها أو ولا وحدة
        $this->assertSame(0, WeeklySchedule::count());
    }

    public function test_moving_a_lesson_onto_an_invalid_assignment_is_rejected(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();
        $lesson = WeeklySchedule::first();

        $bad = $this->makeOffStageAssignment();

        $this->actingAsSupervisor()
            ->putJson("/api/supervisor/schedule/{$lesson->id}", [
                'teacher_assignment_id' => $bad->id,
            ])
            ->assertStatus(422);

        $this->assertSame($this->mathAssignmentA->id, $lesson->fresh()->teacher_assignment_id);
    }

    // ==================== الخانات الفاضية ====================

    private function freeSlots(int $assignmentId)
    {
        return $this->actingAsSupervisor()
            ->getJson('/api/supervisor/schedule/free-slots?teacher_assignment_id='.$assignmentId);
    }

    public function test_a_fresh_assignment_has_the_whole_week_free(): void
    {
        $data = $this->freeSlots($this->mathAssignmentA->id)
            ->assertOk()
            ->json('data');

        // 5 أيام × حصص الدرس باليوم (بدون الاستراحة)
        $classPeriods = 0;

        foreach (config('school.periods') as $period) {
            if ($period['type'] === 'class') {
                $classPeriods++;
            }
        }

        $this->assertSame($classPeriods * count(config('school.school_days')), $data['free_count']);
        $this->assertSame(0, $data['taken_count']);
    }

    public function test_a_booked_slot_disappears_from_the_free_list(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        $slots = $this->freeSlots($this->mathAssignmentA->id)->assertOk()->json('data.free_slots');

        foreach ($slots as $slot) {
            $taken = $slot['day_of_week'] === 'sunday' && $slot['period_number'] === 1;

            $this->assertFalse($taken, 'الخانة المحجوزة لازم تختفي من القائمة');
        }
    }

    public function test_a_slot_taken_by_the_section_is_not_offered_to_another_subject(): void
    {
        // الشعبة نفسها محجوزة بالرياضيات، فما بينفع نحط عربي بنفس الخانة
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        $slots = $this->freeSlots($this->arabicAssignmentA->id)->assertOk()->json('data.free_slots');

        foreach ($slots as $slot) {
            $clash = $slot['day_of_week'] === 'sunday' && $slot['period_number'] === 1;

            $this->assertFalse($clash);
        }
    }

    public function test_the_free_slot_can_be_posted_back_as_is(): void
    {
        // القيم يلي بترجع لازم تكون جاهزة للإرسال بدون تعديل
        $slot = $this->freeSlots($this->mathAssignmentA->id)->assertOk()->json('data.free_slots.0');

        $this->actingAsSupervisor()->postJson('/api/supervisor/schedule', [
            'teacher_assignment_id' => $slot['teacher_assignment_id'],
            'day_of_week' => $slot['day_of_week'],
            'period_number' => $slot['period_number'],
        ])->assertCreated();
    }

    public function test_the_break_period_is_never_offered(): void
    {
        $slots = $this->freeSlots($this->mathAssignmentA->id)->assertOk()->json('data.free_slots');

        $breaks = [];

        foreach (config('school.periods') as $number => $period) {
            if ($period['type'] !== 'class') {
                $breaks[] = $number;
            }
        }

        foreach ($slots as $slot) {
            $this->assertNotContains($slot['period_number'], $breaks);
        }
    }

    public function test_a_full_week_reports_no_free_slot(): void
    {
        foreach (config('school.school_days') as $day) {
            foreach (config('school.periods') as $number => $period) {
                if ($period['type'] === 'class') {
                    $this->addLesson($this->mathAssignmentA->id, $day, $number)->assertCreated();
                }
            }
        }

        $response = $this->freeSlots($this->mathAssignmentA->id)->assertOk();

        $this->assertSame(0, $response->json('data.free_count'));
        $this->assertStringContainsString('ما في خانة فاضية', $response->json('message'));
    }

    public function test_the_days_carry_arabic_labels(): void
    {
        $slot = $this->freeSlots($this->mathAssignmentA->id)->assertOk()->json('data.free_slots.0');

        $this->assertSame('sunday', $slot['day_of_week']);
        $this->assertSame('الأحد', $slot['day_label']);
    }

    public function test_free_slots_needs_a_valid_assignment(): void
    {
        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/schedule/free-slots?teacher_assignment_id=99999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('teacher_assignment_id');
    }

    // ==================== نقطة بداية بناء الجدول ====================

    private function builder(int $sectionId)
    {
        return $this->actingAsSupervisor()
            ->getJson('/api/supervisor/schedule/builder/'.$sectionId);
    }

    public function test_the_builder_reports_an_empty_section(): void
    {
        $response = $this->builder($this->sectionA->id)->assertOk();

        $this->assertSame(0, $response->json('data.slots.filled'));
        $this->assertSame(35, $response->json('data.slots.total'));
        $this->assertSame(35, $response->json('data.slots.empty'));
        $this->assertFalse($response->json('data.slots.is_complete'));
        $this->assertStringContainsString('35', $response->json('message'));
    }

    public function test_the_builder_counts_down_as_lessons_are_added(): void
    {
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 2)->assertCreated();

        $response = $this->builder($this->sectionA->id)->assertOk();

        $this->assertSame(2, $response->json('data.slots.filled'));
        $this->assertSame(33, $response->json('data.slots.empty'));
        $this->assertStringContainsString('ضلّ 33', $response->json('message'));
    }

    public function test_the_builder_only_offers_subjects_of_the_class_stage(): void
    {
        /*
         | مادة مو مربوطة بمرحلة الصف ما لازم تظهر — متل التاريخ بالابتدائي.
         | makeSubject بتربط بكل المراحل، فمنعملها بالإنشاء المباشر.
         */
        Subject::create([
            'name' => 'التاريخ',
            'passing_grade' => 50,
            'description' => 'مادة ثانوية',
        ]);

        $names = array_column($this->builder($this->sectionA->id)->assertOk()->json('data.subjects'), 'subject');

        $this->assertNotContains('التاريخ', $names);
        $this->assertContains('رياضيات', $names);
    }

    public function test_the_builder_lists_only_teachers_of_the_same_stage(): void
    {
        // معلم من مرحلة تانية ما لازم يظهر كمؤهل
        $otherStage = $this->makeStage('middle');
        $outsider = $this->makeTeacher($this->math, $otherStage, 'Middle Teacher');

        $subjects = $this->builder($this->sectionA->id)->assertOk()->json('data.subjects');

        foreach ($subjects as $subject) {
            $ids = array_column($subject['eligible_teachers'], 'teacher_id');

            $this->assertNotContains($outsider->id, $ids);
        }
    }

    public function test_the_specialist_teacher_is_listed_first(): void
    {
        $subjects = $this->builder($this->sectionA->id)->assertOk()->json('data.subjects');

        foreach ($subjects as $subject) {
            if ($subject['subject'] !== 'رياضيات') {
                continue;
            }

            $this->assertTrue($subject['eligible_teachers'][0]['is_specialist']);
            $this->assertSame('Math Teacher', $subject['eligible_teachers'][0]['teacher_name']);
        }
    }

    public function test_the_builder_shows_which_subjects_already_have_assignments(): void
    {
        $subjects = $this->builder($this->sectionA->id)->assertOk()->json('data.subjects');

        $seen = [];

        foreach ($subjects as $subject) {
            $seen[$subject['subject']] = $subject;
        }

        // الرياضيات مكلّفة بالـ setUp، فلازم يرجع رقم التكليف جاهز للجدولة
        $this->assertTrue($seen['رياضيات']['is_assigned']);
        $this->assertSame(
            $this->mathAssignmentA->id,
            $seen['رياضيات']['assignments'][0]['assignment_id']
        );
    }

    public function test_a_full_week_is_reported_as_complete(): void
    {
        foreach (config('school.school_days') as $day) {
            foreach (config('school.periods') as $number => $period) {
                if ($period['type'] === 'class') {
                    $this->addLesson($this->mathAssignmentA->id, $day, $number)->assertCreated();
                }
            }
        }

        $response = $this->builder($this->sectionA->id)->assertOk();

        $this->assertTrue($response->json('data.slots.is_complete'));
        $this->assertSame(0, $response->json('data.slots.empty'));
        $this->assertStringContainsString('مكتمل', $response->json('message'));
    }

    // ==================== البناء يوم بيوم ====================

    private function buildDay(int $sectionId, string $day, array $lessons)
    {
        return $this->actingAsSupervisor()
            ->postJson('/api/supervisor/schedule/build/'.$sectionId, ['days' => [$day => $lessons]]);
    }

    /** حصص يوم كامل من مادة ومعلم واحد */
    private function fullDay(int $subjectId, int $teacherId): array
    {
        $lessons = [];

        foreach (config('school.periods') as $number => $period) {
            if ($period['type'] === 'class') {
                $lessons[] = [
                    'period_number' => $number,
                    'subject_id' => $subjectId,
                    'teacher_id' => $teacherId,
                ];
            }
        }

        return $lessons;
    }

    public function test_building_a_day_creates_the_assignment_by_itself(): void
    {
        // ما عملنا تكليف للعربي بشعبة B — لازم ينعمل لحالو
        $teacher = $this->arabicAssignmentA->teacher_id;
        $subject = $this->arabicAssignmentA->subject_id;

        $this->buildDay($this->sectionB->id, 'sunday', [
            ['period_number' => 1, 'subject_id' => $subject, 'teacher_id' => $teacher],
        ])->assertCreated();

        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $teacher,
            'subject_id' => $subject,
            'section_id' => $this->sectionB->id,
        ]);
    }

    public function test_the_same_assignment_is_reused_not_duplicated(): void
    {
        $teacher = $this->mathAssignmentA->teacher_id;
        $subject = $this->mathAssignmentA->subject_id;

        $this->buildDay($this->sectionA->id, 'sunday', [
            ['period_number' => 1, 'subject_id' => $subject, 'teacher_id' => $teacher],
            ['period_number' => 2, 'subject_id' => $subject, 'teacher_id' => $teacher],
        ])->assertCreated();

        $count = TeacherAssignment::where('teacher_id', $teacher)
            ->where('subject_id', $subject)
            ->where('section_id', $this->sectionA->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_the_response_counts_down_and_names_the_next_day(): void
    {
        $response = $this->buildDay(
            $this->sectionA->id,
            'sunday',
            $this->fullDay($this->mathAssignmentA->subject_id, $this->mathAssignmentA->teacher_id)
        )->assertCreated();

        $this->assertSame(7, $response->json('data.created_count'));
        $this->assertSame(28, $response->json('data.slots.empty'));
        $this->assertSame('monday', $response->json('data.next_day'));
        $this->assertTrue($response->json('data.days.sunday.is_complete'));
    }

    public function test_a_teacher_busy_in_another_section_is_rejected(): void
    {
        // الشرط الأساسي: الشخص ما بيكون بمكانين بنفس الوقت
        $this->addLesson($this->mathAssignmentA->id, 'sunday', 1)->assertCreated();

        $response = $this->buildDay($this->sectionB->id, 'sunday', [
            [
                'period_number' => 1,
                'subject_id' => $this->mathAssignmentA->subject_id,
                'teacher_id' => $this->mathAssignmentA->teacher_id,
            ],
        ])->assertStatus(422);

        $this->assertSame('تعارض مع الجدول المحفوظ', $response->json('data.errors.0.reason'));
        $this->assertSame('teacher_busy', $response->json('data.errors.0.conflicts.0.type'));
    }

    public function test_a_subject_outside_the_stage_is_rejected(): void
    {
        $outside = Subject::create([
            'name' => 'فيزياء',
            'passing_grade' => 50,
            'description' => 'مادة ثانوية',
        ]);

        $response = $this->buildDay($this->sectionA->id, 'sunday', [
            [
                'period_number' => 1,
                'subject_id' => $outside->id,
                'teacher_id' => $this->mathAssignmentA->teacher_id,
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('غير مدرَّسة', $response->json('data.errors.0.reason'));
        $this->assertDatabaseCount('weekly_schedules', 0);
    }

    public function test_a_teacher_from_another_stage_is_rejected(): void
    {
        $middle = $this->makeStage('middle');
        $outsider = $this->makeTeacher($this->math, $middle, 'Middle Teacher');

        $response = $this->buildDay($this->sectionA->id, 'sunday', [
            [
                'period_number' => 1,
                'subject_id' => $this->mathAssignmentA->subject_id,
                'teacher_id' => $outsider->id,
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('مرحلة', $response->json('data.errors.0.reason'));
    }

    public function test_the_break_period_is_rejected(): void
    {
        $break = null;

        foreach (config('school.periods') as $number => $period) {
            if ($period['type'] !== 'class') {
                $break = $number;
                break;
            }
        }

        $response = $this->buildDay($this->sectionA->id, 'sunday', [
            [
                'period_number' => $break,
                'subject_id' => $this->mathAssignmentA->subject_id,
                'teacher_id' => $this->mathAssignmentA->teacher_id,
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('استراحة', $response->json('data.errors.0.reason'));
    }

    public function test_the_same_slot_twice_in_one_request_is_rejected(): void
    {
        $response = $this->buildDay($this->sectionA->id, 'sunday', [
            ['period_number' => 1, 'subject_id' => $this->mathAssignmentA->subject_id, 'teacher_id' => $this->mathAssignmentA->teacher_id],
            ['period_number' => 1, 'subject_id' => $this->arabicAssignmentA->subject_id, 'teacher_id' => $this->arabicAssignmentA->teacher_id],
        ])->assertStatus(422);

        $this->assertStringContainsString('نفس الطلب', $response->json('data.errors.0.reason'));
        $this->assertDatabaseCount('weekly_schedules', 0);
    }

    public function test_nothing_is_saved_when_one_lesson_fails(): void
    {
        // 6 حصص سليمة وواحدة غلط → ولا وحدة بتنحفظ
        $lessons = $this->fullDay($this->mathAssignmentA->subject_id, $this->mathAssignmentA->teacher_id);
        $lessons[3]['period_number'] = 99;

        $this->buildDay($this->sectionA->id, 'sunday', $lessons)->assertStatus(422);

        $this->assertDatabaseCount('weekly_schedules', 0);
    }

    public function test_an_unknown_day_is_rejected(): void
    {
        $this->actingAsSupervisor()
            ->postJson('/api/supervisor/schedule/build/'.$this->sectionA->id, [
                'days' => ['friday' => [
                    ['period_number' => 1, 'subject_id' => $this->mathAssignmentA->subject_id, 'teacher_id' => $this->mathAssignmentA->teacher_id],
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_the_whole_week_can_be_sent_in_one_request(): void
    {
        $days = [];

        foreach (config('school.school_days') as $day) {
            $days[$day] = $this->fullDay(
                $this->mathAssignmentA->subject_id,
                $this->mathAssignmentA->teacher_id
            );
        }

        $response = $this->actingAsSupervisor()
            ->postJson('/api/supervisor/schedule/build/'.$this->sectionA->id, ['days' => $days])
            ->assertCreated();

        $this->assertSame(35, $response->json('data.created_count'));
        $this->assertTrue($response->json('data.slots.is_complete'));
        $this->assertNull($response->json('data.next_day'));
        $this->assertStringContainsString('مكتمل', $response->json('message'));
    }
}
