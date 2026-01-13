<?php

namespace Database\Seeders;

use App\Models\EventSourceItem;
use Illuminate\Database\Seeder;

class EventSourceItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EventSourceItem::factory()->count(3)->create();
    }
}
