<?php

namespace Database\Seeders;

use App\Models\TeacherTask;
use App\Models\TeacherAssignment;
use Illuminate\Database\Seeder;

class TeacherTasksSeeder extends Seeder
{
    public function run(): void
    {
        if (TeacherAssignment::count() === 0) {
            $this->command->warn('لا يوجد تعيينات معلمين. قم بتشغيل TeacherAssignmentsSeeder أولاً.');
            return;
        }

        $statuses = ['in_progress', 'completed'];

        for ($i = 0; $i < 25; $i++) {
            TeacherTask::create([
                'teacher_assignment_id' => TeacherAssignment::inRandomOrder()->first()->id,
                'title' => fake()->sentence(),
                'description' => fake()->optional()->paragraph(),
                'is_important' => fake()->boolean(20),
                'status' => fake()->randomElement($statuses),
                'due_date' => fake()->dateTimeBetween('now', '+2 weeks'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
