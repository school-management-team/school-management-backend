<?php
// database/factories/UserFactory.php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password123'), // كلمة مرور موحدة للاختبار
            'role' => fake()->randomElement(['teacher', 'student', 'guardian']),
            'phone' => fake()->phoneNumber(),
            'is_active' => fake()->boolean(70), // 70% مفعل
            'email_verified_at' => now(),
            'last_login_at' => fake()->optional()->dateTimeThisMonth(),
            'last_login_ip' => fake()->optional()->ipv4(),
            'password_changed_at' => now(),
            'failed_attempts' => 0,
            'locked_until' => null,
            'remember_token' => Str::random(10),
            'student_id' => null,
            'teacher_id' => null,
            'admin_id' => null,
        ];
    }

    // State: مستخدم مفعل
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    // State: مستخدم غير مفعل (معلق)
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    // State: دور معلم
    public function teacher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'teacher',
        ]);
    }

    // State: دور طالب
    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'student',
        ]);
    }


}
