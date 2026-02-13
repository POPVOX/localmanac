<?php

use App\Jobs\RunScraperRun;
use App\Livewire\Admin\Scrapers\Index;
use App\Models\City;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('queues a scraper run from the admin index', function () {
    Queue::fake();

    $user = User::factory()->create();

    $city = City::create(['name' => 'Queue City', 'slug' => 'queue-city']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Queued Scraper',
        'slug' => 'queued-scraper',
        'type' => 'rss',
        'is_enabled' => true,
        'source_url' => 'https://example.com/feed',
        'config' => [],
    ]);

    Livewire::actingAs($user)->test(Index::class)
        ->call('queueRun', $scraper->id);

    $run = ScraperRun::first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('queued')
        ->and($run->items_found)->toBe(0)
        ->and($run->items_created)->toBe(0)
        ->and($run->items_updated)->toBe(0);

    Queue::assertPushed(RunScraperRun::class, fn (RunScraperRun $job): bool => $job->runId === $run?->id);

    Livewire::actingAs($user)->test(Index::class)
        ->call('queueRun', $scraper->id);

    expect(ScraperRun::count())->toBe(1);
});

it('expires stale queued runs and allows a fresh queue', function () {
    Queue::fake();

    $now = CarbonImmutable::parse('2026-02-13 21:30:00', 'UTC');
    CarbonImmutable::setTestNow($now);

    $user = User::factory()->create();

    $city = City::create(['name' => 'Queue City', 'slug' => 'queue-city']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Queued Scraper',
        'slug' => 'queued-scraper-stale',
        'type' => 'rss',
        'is_enabled' => true,
        'source_url' => 'https://example.com/feed',
        'config' => [],
    ]);

    $staleRun = ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    $staleRun->forceFill([
        'created_at' => $now->subMinutes(45),
        'updated_at' => $now->subMinutes(45),
    ])->save();

    Livewire::actingAs($user)->test(Index::class)
        ->call('queueRun', $scraper->id);

    $staleRun->refresh();
    $newRun = ScraperRun::query()->latest('id')->first();

    expect($staleRun->status)->toBe('failed')
        ->and($staleRun->finished_at)->not->toBeNull()
        ->and($newRun)->not->toBeNull()
        ->and($newRun?->id)->not->toBe($staleRun->id)
        ->and($newRun?->status)->toBe('queued');

    Queue::assertPushed(RunScraperRun::class, fn (RunScraperRun $job): bool => $job->runId === $newRun?->id);

    CarbonImmutable::setTestNow();
});
