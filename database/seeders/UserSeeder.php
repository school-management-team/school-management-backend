<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {

       User::create([
            'user_name' => 'System Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'phone' => '0900000000',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
