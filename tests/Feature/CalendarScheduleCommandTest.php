<?php

use App\Jobs\RunEventSourceIngestion;
use App\Models\City;
use App\Models\EventIngestionRun;
use App\Models\EventSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

it('queues only due event sources and avoids duplicate runs', function () {
    Queue::fake();

    $nowUtc = CarbonImmutable::parse('2026-02-13 16:00:00', 'UTC');
    CarbonImmutable::setTestNow($nowUtc);

    $city = City::create([
        'name' => 'Event Scheduler City',
        'slug' => 'event-scheduler-city',
        'timezone' => 'UTC',
    ]);

    $dueHourlySource = EventSource::create([
        'city_id' => $city->id,
        'name' => 'Due Hourly Source',
        'source_type' => 'ics',
        'source_url' => 'https://example.com/due-hourly.ics',
        'frequency' => 'hourly',
        'is_active' => true,
        'config' => [],
        'last_run_at' => $nowUtc->subMinutes(65),
    ]);

    $notDueDailySource = EventSource::create([
        'city_id' => $city->id,
        'name' => 'Not Due Daily Source',
        'source_type' => 'rss',
        'source_url' => 'https://example.com/not-due-daily.rss',
        'frequency' => 'daily',
        'is_active' => true,
        'config' => [],
        'last_run_at' => $nowUtc->subHours(10),
    ]);

    $neverRunWeeklySource = EventSource::create([
        'city_id' => $city->id,
        'name' => 'Never Run Weekly Source',
        'source_type' => 'json_api',
        'source_url' => 'https://example.com/never-run.json',
        'frequency' => 'weekly',
        'is_active' => true,
        'config' => [],
        'last_run_at' => null,
    ]);

    $queuedSource = EventSource::create([
        'city_id' => $city->id,
        'name' => 'Already Queued Source',
        'source_type' => 'html',
        'source_url' => 'https://example.com/queued',
        'frequency' => 'hourly',
        'is_active' => true,
        'config' => [],
        'last_run_at' => $nowUtc->subHours(2),
    ]);

    $inactiveDueSource = EventSource::create([
        'city_id' => $city->id,
        'name' => 'Inactive Due Source',
        'source_type' => 'ics',
        'source_url' => 'https://example.com/inactive.ics',
        'frequency' => 'hourly',
        'is_active' => false,
        'config' => [],
        'last_run_at' => $nowUtc->subHours(3),
    ]);

    $existingQueuedRun = EventIngestionRun::create([
        'event_source_id' => $queuedSource->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_written' => 0,
    ]);

    $this->artisan('calendar:schedule')->assertExitCode(0);

    $dueRun = EventIngestionRun::query()
        ->where('event_source_id', $dueHourlySource->id)
        ->where('status', 'queued')
        ->latest('id')
        ->first();

    $weeklyRun = EventIngestionRun::query()
        ->where('event_source_id', $neverRunWeeklySource->id)
        ->where('status', 'queued')
        ->latest('id')
        ->first();

    expect($dueRun)->not->toBeNull()
        ->and($weeklyRun)->not->toBeNull();

    expect(EventIngestionRun::query()->where('event_source_id', $notDueDailySource->id)->where('status', 'queued')->exists())->toBeFalse();

    expect(EventIngestionRun::query()->where('event_source_id', $inactiveDueSource->id)->where('status', 'queued')->exists())->toBeFalse();

    $queuedRunIds = EventIngestionRun::query()
        ->where('event_source_id', $queuedSource->id)
        ->where('status', 'queued')
        ->pluck('id')
        ->all();

    expect($queuedRunIds)->toBe([$existingQueuedRun->id]);

    Queue::assertPushed(RunEventSourceIngestion::class, 2);
    Queue::assertPushed(RunEventSourceIngestion::class, fn (RunEventSourceIngestion $job): bool => $job->eventSourceId === $dueHourlySource->id
        && $job->runId === $dueRun?->id
        && $job->queue === 'calendar');
    Queue::assertPushed(RunEventSourceIngestion::class, fn (RunEventSourceIngestion $job): bool => $job->eventSourceId === $neverRunWeeklySource->id
        && $job->runId === $weeklyRun?->id
        && $job->queue === 'calendar');

    CarbonImmutable::setTestNow();
});

it('expires stale event runs and queues a replacement', function () {
    Queue::fake();

    $nowUtc = CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($nowUtc);

    $city = City::create([
        'name' => 'Recovery City',
        'slug' => 'recovery-city',
        'timezone' => 'UTC',
    ]);
    $source = EventSource::create([
        'city_id' => $city->id,
        'name' => 'Recoverable Calendar',
        'source_type' => 'ics',
        'source_url' => 'https://example.com/recovery.ics',
        'frequency' => 'hourly',
        'is_active' => true,
        'config' => [],
        'last_run_at' => $nowUtc->subHours(2),
    ]);
    $staleRun = EventIngestionRun::create([
        'event_source_id' => $source->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_written' => 0,
    ]);
    $staleRun->forceFill([
        'created_at' => $nowUtc->subMinutes(20),
        'updated_at' => $nowUtc->subMinutes(20),
    ])->save();

    $this->artisan('calendar:schedule')->assertExitCode(0);

    $replacement = EventIngestionRun::query()
        ->where('event_source_id', $source->id)
        ->where('status', 'queued')
        ->latest('id')
        ->first();

    expect($staleRun->refresh()->status)->toBe('failed')
        ->and($staleRun->finished_at)->not->toBeNull()
        ->and($replacement)->not->toBeNull()
        ->and($replacement?->id)->not->toBe($staleRun->id);

    Queue::assertPushed(RunEventSourceIngestion::class, fn (RunEventSourceIngestion $job): bool => $job->runId === $replacement?->id);

    CarbonImmutable::setTestNow();
});
