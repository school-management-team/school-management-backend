<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Guardian;
use App\Models\LessonSubstitution;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class GuardianScheduleTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private Guardian $guardian;
    private Student $child;
    private Student $otherChild;
    private $supervisor;
    private $section;
    private Subject $math;
    private Teacher $mathTeacher;
    private string $sunday = '2026-08-16';
    private string $friday = '2026-08-21';

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = $this->makeSupervisor();
        $stage = $this->makeStage();
        $this->math = $this->makeSubject('رياضيات');
        $arabic = $this->makeSubject('عربي');

        $this->section = $this->makeSection('A');
        $this->child = $this->makeStudent($this->section, '10001');
        $this->otherChild = $this->makeStudent($this->makeSection('B'), '10002');

        $this->mathTeacher = $this->makeTeacher($this->math, $stage, 'Math Teacher');
        $arabicTeacher = $this->makeTeacher($arabic, $stage, 'Arabic Teacher');

        // تكليف واحد لكل معلم — عليه unique(teacher, subject, section)
        $mathAssignment = $this->makeAssignment($this->mathTeacher, $this->math, $this->section);
        $arabicAssignment = $this->makeAssignment($arabicTeacher, $arabic, $this->section);

        $this->lesson($mathAssignment, 'sunday', 1);
        $this->lesson($arabicAssignment, 'sunday', 2);
        $this->lesson($mathAssignment, 'monday', 1);

        $this->guardian = Guardian::create([
            'relationship' => 'father',
            'number_of_children' => 1,
            'user_id' => $this->makeUser('guardian', 'Parent')->id,
        ]);

        $this->guardian->students()->attach($this->child->id);
    }

    private function lesson($assignment, string $day, int $period): WeeklySchedule
    {
        $times = config("school.periods.{$period}");

        return WeeklySchedule::create([
            'teacher_id' => $assignment->teacher_id,
            'teacher_assignment_id' => $assignment->id,
            'day_of_week' => $day,
            'period_number' => $period,
            'start_time' => $times['start'],
            'end_time' => $times['end'],
            'type' => 'class',
        ]);
    }

    private function asGuardian(): self
    {
        return $this->actingAs($this->guardian->user, 'sanctum');
    }

    // ==================== الأسبوع ====================

    public function test_guardian_sees_the_weekly_grid(): void
    {
        $response = $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule")
            ->assertOk()
            ->assertJsonPath('data.student.student_number', '10001')
            ->assertJsonCount(5, 'data.schedule')          // 5 أيام دوام
            ->assertJsonCount(8, 'data.schedule.sunday');  // 8 خانات باليوم

        $this->assertSame('رياضيات', $response->json('data.schedule.sunday.0.subject'));
        $this->assertSame('Math Teacher', $response->json('data.schedule.sunday.0.teacher_name'));
        $this->assertSame('عربي', $response->json('data.schedule.sunday.1.subject'));
    }

    public function test_break_periods_are_marked_in_the_grid(): void
    {
        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule")
            ->assertOk()
            ->assertJsonPath('data.schedule.sunday.3.type', 'break');
    }

    public function test_empty_slots_have_no_subject(): void
    {
        $slot = $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule")
            ->json('data.schedule.tuesday.0');

        $this->assertArrayNotHasKey('subject', $slot);
        $this->assertSame('class', $slot['type']);
    }

    public function test_the_period_definitions_are_returned(): void
    {
        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule")
            ->assertOk()
            ->assertJsonCount(8, 'data.periods')
            ->assertJsonPath('data.periods.0.start_time', '08:00:00');
    }

    // ==================== يوم محدد ====================

    public function test_a_specific_date_returns_only_that_days_lessons(): void
    {
        $response = $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule?date={$this->sunday}")
            ->assertOk()
            ->assertJsonPath('data.is_school_day', true)
            ->assertJsonPath('data.day_of_week', 'sunday');

        $this->assertCount(2, $response->json('data.lessons'));
        $this->assertSame('رياضيات', $response->json('data.lessons.0.subject'));
    }

    public function test_a_weekend_date_returns_no_lessons(): void
    {
        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule?date={$this->friday}")
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false)
            ->assertJsonPath('data.reason', 'weekend')
            ->assertJsonCount(0, 'data.lessons');
    }

    public function test_a_holiday_returns_no_lessons(): void
    {
        Announcement::create([
            'supervisor_id' => $this->supervisor->id,
            'title' => 'عيد الفطر',
            'description' => 'عطلة',
            'type' => 'holiday',
            'date' => $this->sunday,
        ]);

        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule?date={$this->sunday}")
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false)
            ->assertJsonPath('data.reason', 'holiday')
            ->assertJsonPath('data.holiday.title', 'عيد الفطر')
            ->assertJsonCount(0, 'data.lessons');
    }

    // ==================== المعلم البديل ====================

    public function test_a_substituted_lesson_shows_the_replacement_teacher(): void
    {
        $substitute = $this->makeTeacher($this->math, $this->makeStage(), 'Substitute Teacher');
        $mathLesson = WeeklySchedule::where('day_of_week', 'sunday')->where('period_number', 1)->first();

        TeacherAttendance::create([
            'teacher_id' => $this->mathTeacher->id,
            'supervisor_id' => $this->supervisor->id,
            'date' => $this->sunday,
            'status' => 'absent',
        ]);

        LessonSubstitution::create([
            'weekly_schedule_id' => $mathLesson->id,
            'absent_teacher_id' => $this->mathTeacher->id,
            'substitute_teacher_id' => $substitute->id,
            'supervisor_id' => $this->supervisor->id,
            'date' => $this->sunday,
            'day_of_week' => 'sunday',
            'period_number' => 1,
            'status' => 'assigned',
        ]);

        $lessons = collect($this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule?date={$this->sunday}")
            ->json('data.lessons'));

        $mathRow = $lessons->firstWhere('subject', 'رياضيات');

        $this->assertTrue($mathRow['is_substituted']);
        $this->assertSame('Substitute Teacher', $mathRow['substitute_teacher_name']);
        $this->assertSame('Math Teacher', $mathRow['teacher_name']);   // المعلم الأصلي لساتو مبيّن

        // الحصة التانية بدون تعويض
        $this->assertFalse($lessons->firstWhere('subject', 'عربي')['is_substituted']);
    }

    public function test_a_cancelled_substitution_is_ignored(): void
    {
        $substitute = $this->makeTeacher($this->math, $this->makeStage(), 'Substitute Teacher');
        $mathLesson = WeeklySchedule::where('day_of_week', 'sunday')->where('period_number', 1)->first();

        LessonSubstitution::create([
            'weekly_schedule_id' => $mathLesson->id,
            'absent_teacher_id' => $this->mathTeacher->id,
            'substitute_teacher_id' => $substitute->id,
            'supervisor_id' => $this->supervisor->id,
            'date' => $this->sunday,
            'day_of_week' => 'sunday',
            'period_number' => 1,
            'status' => 'cancelled',
        ]);

        $lessons = collect($this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule?date={$this->sunday}")
            ->json('data.lessons'));

        $this->assertFalse($lessons->firstWhere('subject', 'رياضيات')['is_substituted']);
    }

    // ==================== العزل ====================

    public function test_a_guardian_cannot_read_another_students_schedule(): void
    {
        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->otherChild->id}/schedule")
            ->assertForbidden();
    }

    public function test_a_child_without_a_section_is_rejected(): void
    {
        $this->child->update(['section_id' => null]);

        $this->asGuardian()
            ->getJson("/api/guardian/children/{$this->child->id}/schedule")
            ->assertStatus(422);
    }

    public function test_other_roles_are_blocked(): void
    {
        $this->actingAs($this->makeUser('teacher', 'Nosy'), 'sanctum')
            ->getJson("/api/guardian/children/{$this->child->id}/schedule")
            ->assertForbidden();
    }
}
