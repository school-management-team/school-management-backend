<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\Classes;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        $types = ['multiple_choice', 'true_false', 'essay'];
        $difficulties = ['easy', 'medium', 'hard'];
        $type = fake()->randomElement($types);

        // إنشاء choices حسب نوع السؤال
        $choices = null;
        if ($type === 'multiple_choice') {
            $choices = [
                ['text' => fake()->sentence(), 'is_correct' => true],
                ['text' => fake()->sentence(), 'is_correct' => false],
                ['text' => fake()->sentence(), 'is_correct' => false],
                ['text' => fake()->sentence(), 'is_correct' => false],
            ];
            // ترتيب عشوائي
            shuffle($choices);
        } elseif ($type === 'true_false') {
            $choices = [
                ['text' => 'True', 'is_correct' => fake()->boolean(50)],
                ['text' => 'False', 'is_correct' => !fake()->boolean(50)],
            ];
        }

        return [
            'teacher_id' => Teacher::inRandomOrder()->first()?->id ?? 1,
            'subject_id' => Subject::inRandomOrder()->first()?->id ?? 1,
            'class_id' => SchoolClass::inRandomOrder()->first()?->id ?? 1,
            'type' => $type,
            'difficulty' => fake()->randomElement($difficulties),
            'text' => fake()->sentence(6),
            'choices' => $choices ? json_encode($choices) : null,
            'model_answer' => fake()->optional(0.7)->sentence(4),
            'usage_count' => fake()->numberBetween(0, 50),
        ];
    }
}
