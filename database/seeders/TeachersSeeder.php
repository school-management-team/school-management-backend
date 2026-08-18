<?php

namespace Database\Seeders;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeachersSeeder extends Seeder
{
    public function run(): void
    {
        // إنشاء معلمين مع مستخدمين مرتبطين
        Teacher::factory()->count(5)->create();
    }
}
