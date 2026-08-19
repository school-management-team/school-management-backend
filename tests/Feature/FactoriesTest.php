<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsSchoolData;
use Tests\TestCase;

/**
 * الفاكتوريات بتنكسر بصمت لما تتغيّر السكيما وما حدا يستعملها.
 * هالاختبارات بتشغّلها فعلياً على داتابيز فاضية.
 */
class FactoriesTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    public function test_the_teacher_factory_works_on_an_empty_database(): void
    {
        $teacher = Teacher::factory()->create();

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id]);
        $this->assertNotNull($teacher->user_id);
        $this->assertNotNull($teacher->subject_id);
        $this->assertNotNull($teacher->stage_id);
        $this->assertSame('teacher', $teacher->user->role);
    }

    public function test_teachers_can_be_created_in_bulk(): void
    {
        Teacher::factory()->count(3)->create();

        $this->assertSame(3, Teacher::count());
        // كل معلم إلو مستخدم خاص فيه (user_id عليه unique)
        $this->assertSame(3, Teacher::pluck('user_id')->unique()->count());
    }

    public function test_a_teacher_can_be_pinned_to_a_subject(): void
    {
        $subject = $this->makeSubject('رياضيات');

        $teacher = Teacher::factory()->forSubject($subject)->create();

        $this->assertSame($subject->id, $teacher->subject_id);
    }

    public function test_the_supervisor_factory_works_on_an_empty_database(): void
    {
        $supervisor = Supervisor::factory()->create();

        $this->assertDatabaseHas('supervisors', ['id' => $supervisor->id]);
        $this->assertSame('supervisor', $supervisor->user->role);
    }

    public function test_the_teacher_factory_reuses_existing_subjects(): void
    {
        $subject = $this->makeSubject('كيمياء');

        Teacher::factory()->count(2)->create();

        // ما بيخلق مواد جديدة إذا في مواد موجودة
        $this->assertSame(1, Subject::count());
        $this->assertSame($subject->id, Teacher::first()->subject_id);
    }
}
