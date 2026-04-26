<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // التأكد من عدم وجود مدير مسبقاً
        if (User::where('email', 'admin@school.com')->exists()) {
            $this->command->info('المدير موجود مسبقاً');
            return;
        }

        User::create([
            'email' => 'admin@school.com',
            'password' => Hash::make('Admin123456'), 
            'role' => 'admin',
            'phone' => '0999999999',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command->info('تم إنشاء حساب المدير بنجاح');
        $this->command->info('البريد: admin@school.com');
        $this->command->info('كلمة المرور: Admin123456');
    }
}