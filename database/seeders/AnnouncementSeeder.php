<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Supervisor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AnnouncementSeeder extends Seeder
{
    
    public function run(): void
    {
        $supervisor = Supervisor::first();

        if (!$supervisor) {
            $this->command?->warn('لا يوجد موجّه. شغّل SupervisorSeeder أولاً.');
            return;
        }

        Announcement::query()->delete();

        $today = Carbon::today();

        $posts = [
            [
                'title' => 'اجتماع أولياء الأمور',
                'description' => 'اجتماع دوري لمناقشة المستوى الدراسي، في قاعة المدرسة الساعة العاشرة صباحاً.',
                'type' => 'academic',
                'is_important' => true,
                'date' => $today->copy()->addDays(7)->toDateString(),
            ],
            [
                'title' => 'بدء الامتحانات الفصلية',
                'description' => 'تبدأ امتحانات الفصل الأول، يرجى الالتزام بالجدول المعلن.',
                'type' => 'academic',
                'is_important' => true,
                'date' => $today->copy()->addDays(20)->toDateString(),
                'end_date' => $today->copy()->addDays(27)->toDateString(),
            ],
            [
                'title' => 'تحديث بيانات الطلاب',
                'description' => 'يرجى من أولياء الأمور تحديث أرقام الهواتف لدى الإدارة.',
                'type' => 'administrative',
                'is_important' => false,
                'date' => $today->copy()->subDays(3)->toDateString(),
            ],
            [
                'title' => 'الرحلة المدرسية السنوية',
                'description' => 'رحلة ترفيهية لطلاب المرحلة الابتدائية.',
                'type' => 'activity',
                'is_important' => false,
                'date' => $today->copy()->addDays(14)->toDateString(),
            ],
            [
                'title' => 'أسبوع النشاطات الرياضية',
                'description' => 'مسابقات رياضية بين الشعب طوال الأسبوع.',
                'type' => 'activity',
                'is_important' => false,
                'date' => $today->copy()->addDays(30)->toDateString(),
                'end_date' => $today->copy()->addDays(34)->toDateString(),
            ],
            [
                'title' => 'عطلة عيد الفطر',
                'description' => 'عطلة رسمية، لا دوام خلال هذه الأيام.',
                'type' => 'holiday',
                'is_important' => true,
                'date' => $today->copy()->addDays(40)->toDateString(),
                'end_date' => $today->copy()->addDays(44)->toDateString(),
            ],
            [
                'title' => 'عطلة عيد الاستقلال',
                'description' => 'عطلة رسمية ليوم واحد.',
                'type' => 'holiday',
                'is_important' => true,
                'date' => $today->copy()->addDays(55)->toDateString(),
            ],
        ];

        foreach ($posts as $post) {
            Announcement::create($post + ['supervisor_id' => $supervisor->id]);
        }

        $holidays = Announcement::holidays()->count();

        $this->command?->info('منشورات: '.count($posts).' (منها '.$holidays.' عطلة رسمية)');
    }
}
