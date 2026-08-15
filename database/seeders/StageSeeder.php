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
            Stage::insert([
            ['name' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'middle', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'high_scientific', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'high_literary', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
