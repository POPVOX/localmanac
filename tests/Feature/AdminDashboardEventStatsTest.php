<?php

use App\Livewire\Admin\Dashboard;
use App\Models\EventIngestionRun;
use App\Models\EventSource;
use Livewire\Livewire;

it('shows event ingestion stats on the dashboard', function () {
    $source = EventSource::factory()->create([
        'name' => 'Visit Wichita (Simpleview)',
    ]);

    EventIngestionRun::factory()->create([
        'event_source_id' => $source->id,
        'status' => 'success',
        'items_written' => 10,
        'started_at' => now()->subHours(2),
        'finished_at' => now()->subHours(1),
    ]);

    EventIngestionRun::factory()->create([
        'event_source_id' => $source->id,
        'status' => 'success',
        'items_written' => 5,
        'started_at' => now()->subDays(3),
        'finished_at' => now()->subDays(3)->addHour(),
    ]);

    EventIngestionRun::factory()->create([
        'event_source_id' => $source->id,
        'status' => 'success',
        'items_written' => 20,
        'started_at' => now()->subDays(10),
        'finished_at' => now()->subDays(10)->addHour(),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSet('eventsLast24h', 10)
        ->assertSet('eventsLast7d', 15)
        ->assertSee('Event ingestion')
        ->assertSee('Visit Wichita (Simpleview)');
});
