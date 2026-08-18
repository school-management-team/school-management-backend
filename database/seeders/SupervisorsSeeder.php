<?php

namespace Database\Seeders;

use App\Models\Supervisor;
use Illuminate\Database\Seeder;

class SupervisorsSeeder extends Seeder
{
    public function run(): void
    {
        Supervisor::factory()->count(3)->create();
    }
}
