<?php

namespace Database\Factories;

use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    /**
     * كل أعمدة جدول teachers إلزامية، فالفاكتوري بيعبّيها كلها ويقدر يشتغل
     * لحاله. المادة والمرحلة بينعادوا من الموجود، وإذا الجدول فاضي بينخلقوا.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'teacher']),
            'subject_id' => fn () => Subject::inRandomOrder()->value('id')
                ?? Subject::create(['name' => fake()->word(), 'passing_grade' => 50])->id,
            // اسم المرحلة enum بالداتابيز، فمحصور بهالقيم
            'stage_id' => fn () => Stage::inRandomOrder()->value('id')
                ?? Stage::create(['name' => 'primary'])->id,
            'cv' => fake()->paragraph(),
            'legal_document_path' => 'doc.pdf',
        ];
    }

    /** معلم لمادة محددة */
    public function forSubject(Subject $subject): self
    {
        return $this->state(fn () => ['subject_id' => $subject->id]);
    }
}
