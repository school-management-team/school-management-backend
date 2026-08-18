<?php

namespace Database\Seeders;

use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SupervisorSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'supervisor@test.com'],
            [
                'user_name' => 'Supervisor User',
                'password' => Hash::make('password123'),
                'role' => 'supervisor',
                'phone' => '0911111111',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        Supervisor::updateOrCreate(
            ['user_id' => $user->id],
            [
                'educational_qualification' => 'master',
                'specialization' => 'الإشراف التربوي',
                'bio' => 'موجّه تربوي مسؤول عن الجداول والحضور والتعويض',
                'cv_file' => 'seeded-supervisor-cv.pdf',
            ]
        );
    }
}
