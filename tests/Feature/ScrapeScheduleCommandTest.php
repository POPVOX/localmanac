<?php

use App\Jobs\RunScraperRun;
use App\Models\City;
use App\Models\Scraper;
use App\Models\ScraperRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

it('queues only due scrapers and avoids duplicate runs', function () {
    Queue::fake();

    $nowUtc = CarbonImmutable::parse('2025-01-02 16:00:00', 'UTC');
    CarbonImmutable::setTestNow($nowUtc);

    $city = City::create([
        'name' => 'Scheduler City',
        'slug' => 'scheduler-city',
        'timezone' => 'UTC',
    ]);

    $dueScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Due Scraper',
        'slug' => 'due-scraper',
        'type' => 'rss',
        'source_url' => 'https://example.com/due',
        'frequency' => 'hourly',
        'is_enabled' => true,
        'config' => [],
    ]);

    $notDueScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Not Due Scraper',
        'slug' => 'not-due-scraper',
        'type' => 'rss',
        'source_url' => 'https://example.com/not-due',
        'frequency' => 'hourly',
        'is_enabled' => true,
        'config' => [],
    ]);

    $queuedScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Queued Scraper',
        'slug' => 'queued-scraper',
        'type' => 'rss',
        'source_url' => 'https://example.com/queued',
        'frequency' => 'hourly',
        'is_enabled' => true,
        'config' => [],
    ]);

    ScraperRun::create([
        'scraper_id' => $dueScraper->id,
        'city_id' => $city->id,
        'status' => 'success',
        'finished_at' => $nowUtc->subMinutes(90),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    ScraperRun::create([
        'scraper_id' => $notDueScraper->id,
        'city_id' => $city->id,
        'status' => 'success',
        'finished_at' => $nowUtc->subMinutes(30),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    $existingQueuedRun = ScraperRun::create([
        'scraper_id' => $queuedScraper->id,
        'city_id' => $city->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    $this->artisan('scrape:schedule')->assertExitCode(0);

    $newDueRun = ScraperRun::query()
        ->where('scraper_id', $dueScraper->id)
        ->where('status', 'queued')
        ->latest('id')
        ->first();

    expect($newDueRun)->not->toBeNull();

    expect(ScraperRun::query()->where('scraper_id', $notDueScraper->id)->where('status', 'queued')->exists())->toBeFalse();

    $queuedRunIds = ScraperRun::query()
        ->where('scraper_id', $queuedScraper->id)
        ->where('status', 'queued')
        ->pluck('id')
        ->all();

    expect($queuedRunIds)->toBe([$existingQueuedRun->id]);

    Queue::assertPushed(RunScraperRun::class, 1);
    Queue::assertPushed(RunScraperRun::class, fn (RunScraperRun $job): bool => $job->runId === $newDueRun?->id
        && $job->connection === 'redis'
        && $job->queue === 'scraping');

    CarbonImmutable::setTestNow();
});

it('expires stale queued runs and still schedules due scrapers', function () {
    Queue::fake();

    $nowUtc = CarbonImmutable::parse('2025-01-02 16:00:00', 'UTC');
    CarbonImmutable::setTestNow($nowUtc);

    $city = City::create([
        'name' => 'Scheduler City',
        'slug' => 'scheduler-city-2',
        'timezone' => 'UTC',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Due Scraper',
        'slug' => 'due-scraper-with-stale-queue',
        'type' => 'rss',
        'source_url' => 'https://example.com/due',
        'frequency' => 'hourly',
        'is_enabled' => true,
        'config' => [],
    ]);

    ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'success',
        'finished_at' => $nowUtc->subMinutes(90),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    $staleQueuedRun = ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    $staleQueuedRun->forceFill([
        'created_at' => $nowUtc->subHours(2),
        'updated_at' => $nowUtc->subHours(2),
    ])->save();

    $this->artisan('scrape:schedule')->assertExitCode(0);

    $staleQueuedRun->refresh();

    $newRun = ScraperRun::query()
        ->where('scraper_id', $scraper->id)
        ->where('status', 'queued')
        ->latest('id')
        ->first();

    expect($staleQueuedRun->status)->toBe('failed')
        ->and($newRun)->not->toBeNull()
        ->and($newRun?->id)->not->toBe($staleQueuedRun->id);

    Queue::assertPushed(RunScraperRun::class, fn (RunScraperRun $job): bool => $job->runId === $newRun?->id
        && $job->connection === 'redis'
        && $job->queue === 'scraping');

    CarbonImmutable::setTestNow();
});
