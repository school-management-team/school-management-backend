<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\Section;
use App\Models\Supervisor;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        $statuses = ['present', 'absent', 'late', 'excused'];
        $status = fake()->randomElement($statuses);

        return [
            'student_id' => Student::inRandomOrder()->first()?->id,
            'section_id' => Section::inRandomOrder()->first()?->id,
            'supervisor_id' => Supervisor::inRandomOrder()->first()?->id ?? 1,
            'date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'status' => $status,
            'excuse' => $status === 'absent' ? fake()->randomElement(['مرض', 'عذر عائلي', 'ظروف طارئة', null]) : null,
        ];
    }


    public function present(): self
    {
        return $this->state(fn() => [
            'status' => 'present',
            'excuse' => null,
        ]);
    }

    public function absent(string $excuse = null): self
    {
        return $this->state(fn() => [
            'status' => 'absent',
            'excuse' => $excuse ?? fake()->randomElement(['مرض', 'عذر عائلي', 'ظروف طارئة']),
        ]);
    }


    public function late(): self
    {
        return $this->state(fn() => [
            'status' => 'late',
            'excuse' => null,
        ]);
    }

    public function excused(): self
    {
        return $this->state(fn() => [
            'status' => 'excused',
            'excuse' => fake()->randomElement(['عذر طبي', 'إجازة رسمية', 'ظروف خاصة']),
        ]);
    }

    public function onDate(string $date): self
    {
        return $this->state(fn() => [
            'date' => $date,
        ]);
    }


    public function forStudent(int $studentId): self
    {
        return $this->state(fn() => [
            'student_id' => $studentId,
        ]);
    }


    public function forSection(int $sectionId): self
    {
        return $this->state(fn() => [
            'section_id' => $sectionId,
        ]);
    }
}
