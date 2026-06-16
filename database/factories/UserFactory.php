<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),

            'password' => Hash::make('password123'),

            'role' => fake()->randomElement([
                'teacher',
                'student',
                'guardian'
            ]),

            'phone' => fake()->unique()->numerify('09########'),

            'is_active' => true,

            'email_verified_at' => now(),

            'last_login_at' => now(),

            'password_changed_at' => now(),

            'failed_attempts' => 0,

            'locked_until' => null,

            'student_id' => null,

            'teacher_id' => null,

            'guardian_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function teacher(): static
    {
        return $this->state(fn () => [
            'role' => 'teacher',
        ]);
    }

    public function student(): static
    {
        return $this->state(fn () => [
            'role' => 'student',
        ]);
    }
}
