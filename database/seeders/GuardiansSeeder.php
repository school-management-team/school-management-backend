<?php

namespace Database\Seeders;

use App\Models\Guardian;
use Illuminate\Database\Seeder;

class GuardiansSeeder extends Seeder
{
    public function run(): void
    {
        Guardian::factory()->count(5)->create();
    }
}
