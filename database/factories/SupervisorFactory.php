<?php

namespace Database\Factories;

use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupervisorFactory extends Factory
{
    protected $model = Supervisor::class;

    public function definition(): array
    {
        return [


            'user_id' => User::factory()->state(['role' => 'supervisor']),
            'educational_qualification' => 'master',
            'specialization' => 'Mathematics',
            'bio' => fake()->paragraph(),
            'cv_file' => 'documents/sample.pdf',

        ];
    }
}
