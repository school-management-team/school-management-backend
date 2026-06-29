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
            'user_name'=>fake()->name(),
            'email' => fake()->unique()->safeEmail(),

            'password' => Hash::make('password123'),

            'role' => fake()->randomElement([
                'teacher',
                'student',
                'guardian'
            ]),

            'phone' => fake()->unique()->numerify('09########'),

            'gender'=>fake()->randomElement(['male','female']),
            'birth_date'=>fake()->date(),
            'status'=>'active'
        ];
    }

}
