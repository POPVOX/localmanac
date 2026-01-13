<?php

namespace Database\Seeders;

use App\Models\EventIngestionRun;
use Illuminate\Database\Seeder;

class EventIngestionRunSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EventIngestionRun::factory()->count(3)->create();
    }
}
