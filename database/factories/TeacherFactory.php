<?php
// database/factories/TeacherFactory.php

namespace Database\Factories;

use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    public function definition(): array
    {
        $educationLevel = fake()->randomElement(['primary', 'middle', 'high']);

        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'teacher_id' => 'TCH' . date('Y') . fake()->unique()->numberBetween(1000, 9999),
            'national_id' => fake()->unique()->numerify('###########'),
            'birth_date' => fake()->dateTimeBetween('-60 years', '-22 years'),
            'gender' => fake()->randomElement(['male', 'female']),
            'address' => fake()->address(),
            'health_status' => fake()->optional()->sentence(),
            'specialization' => fake()->randomElement([
                'رياضيات', 'فيزياء', 'كيمياء', 'علوم', 'لغة عربية',
                'لغة إنجليزية', 'لغة فرنسية', 'تاريخ', 'جغرافيا', 'فلسفة',
                'تربية إسلامية', 'تربية فنية', 'تربية رياضية', 'معلوماتية'
            ]),
            'education_level' => $educationLevel,
            'high_school_branch' => $educationLevel === 'high'
                ? fake()->randomElement(['scientific', 'literary'])
                : null,
            'is_class_teacher' => fake()->boolean(20),
            'years_of_experience' => fake()->numberBetween(0, 35),
            'weekly_hours' => fake()->numberBetween(20, 40),
            'hire_date' => fake()->dateTimeBetween('-10 years', 'now'),
            'cv_path' => null,
            'legal_document_path' => null,
            'status' => fake()->randomElement(['unverified','pending', 'active', 'active', 'active']), // 75% active
            'rating' => fake()->randomFloat(2, 2, 5),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }
}
