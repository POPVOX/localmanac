<?php

use App\Jobs\RunEventSourceIngestion;
use App\Models\City;
use App\Models\EventIngestionRun;
use App\Models\EventSource;

test('event ingestion dispatches through the redis calendar queue consumed by Horizon', function () {
    $job = new RunEventSourceIngestion(123, 456);

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('calendar');
});

test('a terminal event ingestion job failure updates its run record', function () {
    $city = City::factory()->create();
    $source = EventSource::factory()->create(['city_id' => $city->id]);
    $run = EventIngestionRun::factory()->create([
        'event_source_id' => $source->id,
        'status' => 'running',
        'started_at' => now(),
    ]);
    $exception = new RuntimeException('Worker timed out');

    (new RunEventSourceIngestion($source->id, $run->id))->failed($exception);

    expect($run->refresh()->status)->toBe('failed')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_class)->toBe(RuntimeException::class)
        ->and($run->error_message)->toBe('Worker timed out');
});
