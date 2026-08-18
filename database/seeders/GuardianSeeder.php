<?php

namespace Database\Seeders;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class GuardianSeeder extends Seeder
{

    public function run(): void
    {
        $students = Student::whereNotNull('section_id')->orderBy('id')->get();

        if ($students->isEmpty()) {
            $this->command?->warn('لا يوجد طلاب موزّعين على شعب. شغّل StudentSectionSeeder أولاً.');
            return;
        }

        $guardians = [
            ['email' => 'guardian@test.com', 'name' => 'Guardian One', 'phone' => '0940000001', 'relationship' => 'father', 'children' => $students->take(2)],
            ['email' => 'guardian2@test.com', 'name' => 'Guardian Two', 'phone' => '0940000002', 'relationship' => 'mother', 'children' => $students->slice(2, 1)],
        ];

        foreach ($guardians as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'user_name' => $data['name'],
                    'password' => Hash::make('password123'),
                    'role' => 'guardian',
                    'phone' => $data['phone'],
                    'gender' => $data['relationship'] === 'father' ? 'male' : 'female',
                    'birth_date' => '1985-01-01',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $guardian = Guardian::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'relationship' => $data['relationship'],
                    'number_of_children' => $data['children']->count(),
                    'verification_student_number' => $data['children']->first()?->student_number,
                ]
            );

            $guardian->students()->sync($data['children']->pluck('id'));

            $this->command?->info("{$data['email']} → {$data['children']->count()} ولد");
        }
    }
}
