<?php

use App\Jobs\IngestChatSource;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\City;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

it('queues only due chat sources and avoids duplicate active runs', function () {
    Queue::fake();

    $nowUtc = CarbonImmutable::parse('2026-03-24 18:00:00', 'UTC');
    CarbonImmutable::setTestNow($nowUtc);

    $city = City::factory()->create();

    $dueHourlySource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Due Hourly Chat Source',
        'frequency' => 'hourly',
        'last_run_at' => $nowUtc->subMinutes(65),
        'is_active' => true,
    ]);

    $notDueDailySource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Not Due Daily Chat Source',
        'frequency' => 'daily',
        'last_run_at' => $nowUtc->subHours(10),
        'is_active' => true,
    ]);

    $neverRunWeeklySource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Never Run Weekly Chat Source',
        'frequency' => 'weekly',
        'last_run_at' => null,
        'is_active' => true,
    ]);

    $queuedSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Already Queued Chat Source',
        'frequency' => 'hourly',
        'last_run_at' => $nowUtc->subHours(2),
        'is_active' => true,
    ]);

    $inactiveDueSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Inactive Due Chat Source',
        'frequency' => 'hourly',
        'last_run_at' => $nowUtc->subHours(2),
        'is_active' => false,
    ]);

    $existingQueuedRun = ChatSourceIngestionRun::create([
        'chat_source_id' => $queuedSource->id,
        'status' => 'queued',
        'pages_found' => 0,
        'pages_changed' => 0,
        'pages_embedded' => 0,
    ]);

    $this->artisan('chat:schedule')->assertExitCode(0);

    $dueRun = ChatSourceIngestionRun::query()
        ->where('chat_source_id', $dueHourlySource->id)
        ->where('status', 'queued')
        ->latest('id')
        ->first();

    $weeklyRun = ChatSourceIngestionRun::query()
        ->where('chat_source_id', $neverRunWeeklySource->id)
        ->where('status', 'queued')
        ->latest('id')
        ->first();

    expect($dueRun)->not->toBeNull()
        ->and($weeklyRun)->not->toBeNull();

    expect(ChatSourceIngestionRun::query()->where('chat_source_id', $notDueDailySource->id)->where('status', 'queued')->exists())->toBeFalse();
    expect(ChatSourceIngestionRun::query()->where('chat_source_id', $inactiveDueSource->id)->where('status', 'queued')->exists())->toBeFalse();

    $queuedRunIds = ChatSourceIngestionRun::query()
        ->where('chat_source_id', $queuedSource->id)
        ->where('status', 'queued')
        ->pluck('id')
        ->all();

    expect($queuedRunIds)->toBe([$existingQueuedRun->id]);

    Queue::assertPushed(IngestChatSource::class, 2);
    Queue::assertPushed(IngestChatSource::class, fn (IngestChatSource $job): bool => $job->chatSourceId === $dueHourlySource->id
        && $job->runId === $dueRun?->id
        && $job->queue === 'ingestion');
    Queue::assertPushed(IngestChatSource::class, fn (IngestChatSource $job): bool => $job->chatSourceId === $neverRunWeeklySource->id
        && $job->runId === $weeklyRun?->id
        && $job->queue === 'ingestion');

    CarbonImmutable::setTestNow();
});

it('expires stale queued and running runs before queueing a fresh scheduled run', function () {
    Queue::fake();

    $nowUtc = CarbonImmutable::parse('2026-03-24 18:00:00', 'UTC');
    CarbonImmutable::setTestNow($nowUtc);

    $city = City::factory()->create();

    $staleQueuedSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'frequency' => 'hourly',
        'last_run_at' => $nowUtc->subHours(2),
        'is_active' => true,
    ]);

    $staleRunningSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'frequency' => 'hourly',
        'last_run_at' => $nowUtc->subHours(2),
        'is_active' => true,
    ]);

    $queuedRun = ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $staleQueuedSource->id,
        'status' => 'queued',
        'started_at' => null,
        'finished_at' => null,
        'created_at' => $nowUtc->subMinutes(ChatSourceIngestionRun::STALE_QUEUED_MINUTES + 1),
        'updated_at' => $nowUtc->subMinutes(ChatSourceIngestionRun::STALE_QUEUED_MINUTES + 1),
    ]);

    $runningRun = ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $staleRunningSource->id,
        'status' => 'running',
        'started_at' => $nowUtc->subMinutes(31),
        'finished_at' => null,
        'created_at' => $nowUtc->subMinutes(31),
        'updated_at' => $nowUtc->subMinutes(31),
    ]);

    $this->artisan('chat:schedule')->assertExitCode(0);

    expect($queuedRun->fresh()?->status)->toBe('failed')
        ->and($runningRun->fresh()?->status)->toBe('failed');

    expect(ChatSourceIngestionRun::query()->where('chat_source_id', $staleQueuedSource->id)->where('status', 'queued')->count())->toBe(1)
        ->and(ChatSourceIngestionRun::query()->where('chat_source_id', $staleRunningSource->id)->where('status', 'queued')->count())->toBe(1);

    Queue::assertPushed(IngestChatSource::class, 2);

    CarbonImmutable::setTestNow();
});
