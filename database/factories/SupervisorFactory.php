<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SupervisorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'=>fake(),

            'educational_qualification'=>'master',

            'specialization'=>'Mathematics',

            'bio'=>fake()->paragraph(),

            'cv_file'=>'documents/sample.pdf'
        ];
    }
}
