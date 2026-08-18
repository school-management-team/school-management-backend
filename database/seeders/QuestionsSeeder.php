<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\Teacher;
use Illuminate\Database\Seeder;

class QuestionsSeeder extends Seeder
{
    public function run(): void
    {
        if (Teacher::count() === 0) {
            $this->command->warn('لا يوجد معلمين. قم بتشغيل UserSeeder أولاً.');
            return;
        }

        Question::factory()->count(50)->create();
    }
}
