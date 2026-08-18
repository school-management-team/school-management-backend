<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Supervisor;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        $types = ['academic', 'administrative', 'activity'];

        return [
            'supervisor_id' => Supervisor::factory(),
            'title' => fake()->sentence(),
            'description' => fake()->paragraphs(2, true),
            'type' => fake()->randomElement($types),
            'is_important' => fake()->boolean(20),
            'date' => fake()->dateTimeBetween('-1 month', 'now'),
            'image_path' => fake()->optional(0.3)->imageUrl(),
            'attachment_path' => fake()->optional(0.2)->filePath(),
        ];
    }
}
