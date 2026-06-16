<?php

namespace Database\Factories;

use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    public function definition(): array
    {
        return [
            'teacher_name' => fake()->name(),

            'birth_date' => fake()->dateTimeBetween(
                '-60 years',
                '-22 years'
            ),

            'gender' => fake()->randomElement([
                'male',
                'female'
            ]),

            'grade' => (string) fake()->numberBetween(1, 12),

            'education_level' => fake()->randomElement([
                'primary',
                'middle',
                'high'
            ]),

            'specialization' => fake()->randomElement([
                'رياضيات',
                'علوم',
                'فيزياء',
                'كيمياء',
                'لغة عربية',
                'لغة إنجليزية'
            ]),

            'cv' => 'cv.pdf',

            'legal_document_path' => 'document.pdf',

            'status' => 'unverified',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
        ]);
    }
}
