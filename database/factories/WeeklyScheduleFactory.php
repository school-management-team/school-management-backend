<?php

namespace Database\Factories;

use App\Models\WeeklySchedule;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class WeeklyScheduleFactory extends Factory
{
    protected $model = WeeklySchedule::class;

    public function definition(): array
    {
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'];
        $types = ['class', 'break', 'free'];

        // تحديد ما إذا كان teacher_assignment_id سيكون null أم لا (50% chance)
        $teacherAssignment = null;
        if (fake()->boolean(50) && TeacherAssignment::count() > 0) {
            $teacherAssignment = TeacherAssignment::inRandomOrder()->first()->id;
        }

        return [
            'teacher_id' => Teacher::inRandomOrder()->first()->id ?? 1,
            'teacher_assignment_id' => $teacherAssignment,
            'day_of_week' => fake()->randomElement($days),
            'period_number' => fake()->numberBetween(1, 8),
            'start_time' => fake()->time('H:i:s'),
            'end_time' => fake()->time('H:i:s'),
            'type' => fake()->randomElement($types),
        ];
    }
}
