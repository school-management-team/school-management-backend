<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Classes;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['A', 'B', 'C']),
            'class_id' => SchoolClass::factory(),
        ];
    }
}
