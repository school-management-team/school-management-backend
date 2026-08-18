<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Supervisor;
use Illuminate\Database\Seeder;

class AnnouncementsSeeder extends Seeder
{
    public function run(): void
    {
        if (Supervisor::count() === 0) {
            $this->command->warn('لا يوجد مشرفين. قم بتشغيل UserSeeder أولاً.');
            return;
        }

        Announcement::factory()->count(20)->create();
    }
}
