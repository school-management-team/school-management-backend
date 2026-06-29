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
        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */

        User::create([
            'user_name' => 'Administrator',
            'email' => 'admin1@school.com',
            'password' => Hash::make('12345678'),
            'role' => 'admin',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'status' => 'active',
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Teachers
        |--------------------------------------------------------------------------
        */

        User::factory()
            ->count(5)
            ->state([
                'role' => 'teacher',
            ])
            ->create()
            ->each(function ($user) {

                $user->teacher()->create(
                    \App\Models\Teacher::factory()
                        ->make()
                        ->toArray()
                );

            });

        /*
        |--------------------------------------------------------------------------
        | Students
        |--------------------------------------------------------------------------
        */

        User::factory()
            ->count(10)
            ->state([
                'role' => 'student',
            ])
            ->create()
            ->each(function ($user) {

                $student = $user->student()->create(
                    \App\Models\Student::factory()
                        ->make()
                        ->toArray()
                );

                $student->update([
                    'student_number' => str_pad(
                        (string) random_int(1, 99999),
                        5,
                        '0',
                        STR_PAD_LEFT
                    )
                ]);

            });

        /*
        |--------------------------------------------------------------------------
        | Supervisors
        |--------------------------------------------------------------------------
        */

        User::factory()
            ->count(3)
            ->state([
                'role' => 'supervisor',
            ])
            ->create()
            ->each(function ($user) {

                $user->supervisor()->create(
                    \App\Models\Supervisor::factory()
                        ->make()
                        ->toArray()
                );

            });

        /*
        |--------------------------------------------------------------------------
        | Guardians
        |--------------------------------------------------------------------------
        */

        User::factory()
            ->count(5)
            ->state([
                'role' => 'guardian',
            ])
            ->create()
            ->each(function ($user) {

                $guardian = $user->guardian()->create(
                    \App\Models\Guardian::factory()
                        ->make()
                        ->toArray()
                );

                $student = Student::inRandomOrder()->first();

                if ($student) {

                    $guardian->update([
                        'verification_student_number' => $student->student_number,
                    ]);

                    $guardian->students()->attach($student->id);
                }

            });
    }
}
