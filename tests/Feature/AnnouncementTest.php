<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\WeeklySchedule;
use App\Services\SchoolCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsSchoolData;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase, BuildsSchoolData;

    private $supervisor;
    private string $sunday = '2026-08-16';   // يوم أحد = دوام
    private string $friday = '2026-08-21';   // جمعة = عطلة أسبوعية

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = $this->makeSupervisor();
    }

    private function actingAsSupervisor(): self
    {
        return $this->actingAs($this->supervisor->user, 'sanctum');
    }

    private function publish(array $overrides = [])
    {
        return $this->actingAsSupervisor()->postJson('/api/supervisor/announcements', array_merge([
            'title' => 'اجتماع أولياء الأمور',
            'description' => 'اجتماع في قاعة المدرسة',
            'type' => 'academic',
            'date' => $this->sunday,
        ], $overrides));
    }

    // ==================== النشر ====================

    public function test_supervisor_publishes_an_announcement(): void
    {
        $this->publish()->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('announcements', [
            'title' => 'اجتماع أولياء الأمور',
            'type' => 'academic',
            'supervisor_id' => $this->supervisor->id,
        ]);
    }

    public function test_supervisor_publishes_a_multi_day_activity(): void
    {
        $this->publish([
            'title' => 'أسبوع النشاطات',
            'type' => 'activity',
            'date' => '2026-09-06',
            'end_date' => '2026-09-10',
        ])->assertCreated()
            ->assertJsonPath('data.days_count', 5)
            ->assertJsonPath('data.is_multi_day', true);
    }

    public function test_supervisor_publishes_an_official_holiday(): void
    {
        $this->publish([
            'title' => 'عيد الفطر',
            'type' => 'holiday',
            'date' => '2026-09-01',
            'end_date' => '2026-09-03',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'holiday')
            ->assertJsonPath('data.days_count', 3);
    }

    public function test_a_single_day_event_counts_as_one_day(): void
    {
        $this->publish()->assertCreated()
            ->assertJsonPath('data.days_count', 1)
            ->assertJsonPath('data.is_multi_day', false);
    }

    public function test_the_end_date_cannot_precede_the_start(): void
    {
        $this->publish(['date' => '2026-09-05', 'end_date' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $this->publish(['type' => 'party'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_an_image_and_attachment_can_be_uploaded(): void
    {
        Storage::fake('public');

        // create() مش image() — تزوير صورة حقيقية بيتطلب إضافة GD
        $this->actingAsSupervisor()->post('/api/supervisor/announcements', [
            'title' => 'حفل التخرج',
            'description' => 'حفل',
            'type' => 'activity',
            'date' => $this->sunday,
            'image' => UploadedFile::fake()->create('poster.jpg', 120, 'image/jpeg'),
            'attachment' => UploadedFile::fake()->create('program.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $announcement = Announcement::first();

        $this->assertNotNull($announcement->image_path);
        $this->assertNotNull($announcement->attachment_path);
        Storage::disk('public')->assertExists($announcement->image_path);
        Storage::disk('public')->assertExists($announcement->attachment_path);
    }

    public function test_deleting_an_announcement_removes_its_files(): void
    {
        Storage::fake('public');

        $this->actingAsSupervisor()->post('/api/supervisor/announcements', [
            'title' => 'حفل', 'description' => 'حفل', 'type' => 'activity', 'date' => $this->sunday,
            'image' => UploadedFile::fake()->create('poster.jpg', 120, 'image/jpeg'),
        ])->assertCreated();

        $announcement = Announcement::first();
        $path = $announcement->image_path;

        $this->actingAsSupervisor()
            ->deleteJson("/api/supervisor/announcements/{$announcement->id}")
            ->assertOk();

        Storage::disk('public')->assertMissing($path);
    }

    // ==================== التعديل والحذف ====================

    public function test_an_announcement_can_be_updated(): void
    {
        $this->publish()->assertCreated();
        $id = Announcement::first()->id;

        $this->actingAsSupervisor()
            ->postJson("/api/supervisor/announcements/{$id}", ['title' => 'عنوان معدّل'])
            ->assertOk();

        $this->assertSame('عنوان معدّل', Announcement::find($id)->title);
    }

    public function test_updating_only_the_end_date_is_still_validated(): void
    {
        $this->publish(['date' => '2026-09-05'])->assertCreated();
        $id = Announcement::first()->id;

        // ما بعتنا date، فالمقارنة لازم تصير مع التاريخ المحفوظ
        $this->actingAsSupervisor()
            ->postJson("/api/supervisor/announcements/{$id}", ['end_date' => '2026-09-01'])
            ->assertStatus(422);
    }

    public function test_an_announcement_can_be_deleted(): void
    {
        $this->publish()->assertCreated();
        $id = Announcement::first()->id;

        $this->actingAsSupervisor()
            ->deleteJson("/api/supervisor/announcements/{$id}")
            ->assertOk();

        $this->assertSame(0, Announcement::count());
    }

    // ==================== القائمة ====================

    public function test_the_list_returns_everything_by_default(): void
    {
        $this->publish(['title' => 'إعلان', 'type' => 'academic']);
        $this->publish(['title' => 'فعالية', 'type' => 'activity']);
        $this->publish(['title' => 'عطلة', 'type' => 'holiday']);

        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonCount(3, 'data.announcements');
    }

    public function test_the_list_can_be_filtered_by_type(): void
    {
        $this->publish(['title' => 'إعلان', 'type' => 'academic']);
        $this->publish(['title' => 'فعالية', 'type' => 'activity']);

        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements?type=activity')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.announcements.0.title', 'فعالية');
    }

    public function test_the_list_can_be_filtered_by_importance(): void
    {
        $this->publish(['title' => 'عادي', 'is_important' => false]);
        $this->publish(['title' => 'مهم', 'is_important' => true]);

        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements?is_important=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.announcements.0.title', 'مهم');
    }

    public function test_the_list_can_be_filtered_by_date_range(): void
    {
        $this->publish(['title' => 'داخل المدى', 'date' => '2026-09-10']);
        $this->publish(['title' => 'برّا المدى', 'date' => '2026-12-20']);

        // فعالية ممتدة بتتقاطع مع المدى من طرفها
        $this->publish(['title' => 'متقاطعة', 'date' => '2026-08-30', 'end_date' => '2026-09-05']);

        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_a_single_announcement_can_be_opened(): void
    {
        $this->publish(['title' => 'اجتماع'])->assertCreated();
        $id = Announcement::first()->id;

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/announcements/{$id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'اجتماع');
    }

    // ==================== التقويم ====================

    public function test_holidays_are_listed_for_a_year(): void
    {
        $this->publish(['title' => 'عيد الفطر', 'type' => 'holiday', 'date' => '2026-09-01', 'end_date' => '2026-09-03']);
        $this->publish(['title' => 'عيد الأضحى', 'type' => 'holiday', 'date' => '2026-11-10']);
        $this->publish(['title' => 'إعلان عادي', 'type' => 'academic', 'date' => '2026-09-15']);

        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements/holidays?year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data.holidays')      // الإعلان العادي مستبعد
            ->assertJsonPath('data.total_days', 4);    // 3 + 1
    }

    public function test_day_status_reports_a_normal_school_day(): void
    {
        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/announcements/day-status?date={$this->sunday}")
            ->assertOk()
            ->assertJsonPath('data.is_school_day', true)
            ->assertJsonPath('data.day_of_week', 'sunday');
    }

    public function test_day_status_reports_a_weekend(): void
    {
        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/announcements/day-status?date={$this->friday}")
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false)
            ->assertJsonPath('data.reason', 'weekend');
    }

    public function test_day_status_reports_a_published_holiday(): void
    {
        $this->publish(['title' => 'عيد الفطر', 'type' => 'holiday', 'date' => $this->sunday]);

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/announcements/day-status?date={$this->sunday}")
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false)
            ->assertJsonPath('data.reason', 'holiday')
            ->assertJsonPath('data.holiday.title', 'عيد الفطر');
    }

    public function test_a_holiday_range_covers_every_day_inside_it(): void
    {
        $this->publish(['title' => 'عطلة', 'type' => 'holiday', 'date' => '2026-09-01', 'end_date' => '2026-09-05']);

        $calendar = app(SchoolCalendarService::class);

        $this->assertTrue($calendar->isHoliday('2026-09-01'));   // البداية
        $this->assertTrue($calendar->isHoliday('2026-09-03'));   // النص
        $this->assertTrue($calendar->isHoliday('2026-09-05'));   // النهاية
        $this->assertFalse($calendar->isHoliday('2026-08-31'));  // قبلها
        $this->assertFalse($calendar->isHoliday('2026-09-06'));  // بعدها
    }

    // ==================== أثر العطلة على النظام ====================

    public function test_attendance_cannot_be_recorded_on_a_holiday(): void
    {
        $this->publish(['title' => 'عيد', 'type' => 'holiday', 'date' => $this->sunday]);

        $teacher = $this->makeTeacher($this->makeSubject('رياضيات'), $this->makeStage());

        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->sunday,
            'records' => [['teacher_id' => $teacher->id, 'status' => 'absent']],
        ])->assertStatus(422)->assertJsonPath('data.reason', 'holiday');

        $this->assertSame(0, TeacherAttendance::count());
    }

    public function test_attendance_cannot_be_recorded_on_a_weekend(): void
    {
        $teacher = $this->makeTeacher($this->makeSubject('رياضيات'), $this->makeStage());

        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->friday,
            'records' => [['teacher_id' => $teacher->id, 'status' => 'absent']],
        ])->assertStatus(422)->assertJsonPath('data.reason', 'weekend');
    }

    public function test_attendance_still_works_on_a_normal_day(): void
    {
        $teacher = $this->makeTeacher($this->makeSubject('رياضيات'), $this->makeStage());

        $this->actingAsSupervisor()->postJson('/api/supervisor/attendance/teachers', [
            'date' => $this->sunday,
            'records' => [['teacher_id' => $teacher->id, 'status' => 'present']],
        ])->assertCreated();

        $this->assertSame(1, TeacherAttendance::count());
    }

    public function test_absent_lessons_returns_nothing_on_a_holiday(): void
    {
        $stage = $this->makeStage();
        $math = $this->makeSubject('رياضيات');
        $teacher = $this->makeTeacher($math, $stage);
        $section = $this->makeSection('A');

        WeeklySchedule::create([
            'teacher_id' => $teacher->id,
            'teacher_assignment_id' => $this->makeAssignment($teacher, $math, $section)->id,
            'day_of_week' => 'sunday', 'period_number' => 1,
            'start_time' => '08:00:00', 'end_time' => '08:45:00', 'type' => 'class',
        ]);

        // نسجّل غيابه قبل ما ننشر العطلة
        TeacherAttendance::create([
            'teacher_id' => $teacher->id,
            'supervisor_id' => $this->supervisor->id,
            'date' => $this->sunday,
            'status' => 'absent',
        ]);

        $this->publish(['title' => 'عيد', 'type' => 'holiday', 'date' => $this->sunday]);

        $this->actingAsSupervisor()
            ->getJson("/api/supervisor/substitutions/absent-lessons?date={$this->sunday}")
            ->assertOk()
            ->assertJsonPath('data.uncovered_count', 0)
            ->assertJsonCount(0, 'data.absent_teachers')
            ->assertJsonPath('data.reason', 'holiday');
    }

    // ==================== الصلاحيات ====================

    public function test_teachers_cannot_publish(): void
    {
        $this->actingAs($this->makeUser('teacher', 'Nosy'), 'sanctum')
            ->postJson('/api/supervisor/announcements', [
                'title' => 'x', 'description' => 'y', 'type' => 'academic', 'date' => $this->sunday,
            ])
            ->assertForbidden();
    }

    public function test_teachers_can_still_read_announcements(): void
    {
        $this->publish()->assertCreated();

        $teacher = Teacher::factory()->create();

        // مسار المعلم القديم لازم يضل شغّال بعد التغييرات
        $this->actingAs($teacher->user, 'sanctum')
            ->getJson('/api/teacher/announcements')
            ->assertOk();
    }

    public function test_an_empty_list_explains_itself(): void
    {
        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements')
            ->assertOk()
            ->assertJsonPath('message', 'لا توجد منشورات بعد')
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.announcements');
    }

    public function test_an_empty_filtered_list_explains_itself(): void
    {
        $this->publish(['type' => 'academic']);

        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements?type=holiday')
            ->assertOk()
            ->assertJsonPath('message', 'لا توجد منشورات من هذا النوع')
            ->assertJsonPath('data.total', 0);
    }

    public function test_the_list_carries_pagination_info(): void
    {
        $this->publish(['title' => 'واحد']);
        $this->publish(['title' => 'اثنان']);

        $this->actingAsSupervisor()
            ->getJson('/api/supervisor/announcements?per_page=1')
            ->assertOk()
            ->assertJsonPath('message', 'عدد المنشورات: 2')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(1, 'data.announcements');
    }

    // ==================== منع التكرار ====================

    public function test_publishing_the_exact_same_post_twice_is_rejected(): void
    {
        $this->publish(['title' => 'اجتماع'])->assertCreated();

        $response = $this->publish(['title' => 'اجتماع'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('يوجد منشور بنفس العنوان', $response->json('message'));

        // الرد بيشاور على المنشور الموجود، وما انخلق نسخة تانية
        $this->assertSame(Announcement::first()->id, $response->json('data.existing_id'));
        $this->assertSame(1, Announcement::count());
    }

    public function test_the_same_title_on_another_date_is_allowed(): void
    {
        $this->publish(['title' => 'اجتماع', 'date' => '2026-09-01'])->assertCreated();
        $this->publish(['title' => 'اجتماع', 'date' => '2026-10-01'])->assertCreated();

        $this->assertSame(2, Announcement::count());
    }

    public function test_the_same_title_with_another_type_is_allowed(): void
    {
        $this->publish(['title' => 'يوم مفتوح', 'type' => 'academic'])->assertCreated();
        $this->publish(['title' => 'يوم مفتوح', 'type' => 'activity'])->assertCreated();

        $this->assertSame(2, Announcement::count());
    }

    public function test_the_rejection_points_at_the_existing_post(): void
    {
        $this->publish(['title' => 'اجتماع'])->assertCreated();
        $existing = Announcement::first();

        $this->publish(['title' => 'اجتماع'])
            ->assertStatus(422)
            ->assertJsonPath('data.existing_id', $existing->id)
            ->assertJsonPath('data.date', $this->sunday);
    }
}
