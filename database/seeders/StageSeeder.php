<?php

namespace Database\Seeders;

use App\Models\Stage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
            $stages = [
            'primary',
            'middle',
            'high_scientific',
            'high_literary',
        ];

        foreach ($stages as $stageName) {
            Stage::updateOrCreate(
                ['name' => $stageName],
                ['name' => $stageName]
            );}
    }
}
