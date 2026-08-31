<?php

use App\Models\City;
use App\Models\EventSource;
use App\Models\Scraper;
use App\Services\Ingestion\Assistant\SourceHealthChecker;

it('checks stale active article and event sources in the background command', function () {
    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'News',
        'slug' => 'news',
        'type' => 'rss',
        'source_url' => 'https://example.gov/news.rss',
        'config' => ['feed_url' => 'https://example.gov/news.rss'],
        'is_enabled' => true,
        'frequency' => 'daily',
    ]);
    $eventSource = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'ics',
        'source_url' => 'https://example.gov/calendar.ics',
        'health_checked_at' => null,
    ]);

    $checker = Mockery::mock(SourceHealthChecker::class);
    $checker->shouldReceive('checkScraper')->once()->withArgs(fn (Scraper $value) => $value->is($scraper))->andReturn([
        'status' => 'healthy', 'proposal' => false, 'error' => null,
    ]);
    $checker->shouldReceive('checkEventSource')->once()->withArgs(fn (EventSource $value) => $value->is($eventSource))->andReturn([
        'status' => 'unhealthy', 'proposal' => true, 'error' => 'Changed endpoint',
    ]);
    app()->instance(SourceHealthChecker::class, $checker);

    $this->artisan('sources:check-health', ['--limit' => 10])
        ->expectsOutput('Checked 2 sources; 1 unhealthy; 1 verified repair proposals.')
        ->assertSuccessful();
});

it('can include inactive legacy failures when generating repair proposals', function () {
    $city = City::factory()->create();
    $eventSource = EventSource::factory()->create([
        'city_id' => $city->id,
        'is_active' => false,
        'health_checked_at' => null,
    ]);

    $checker = Mockery::mock(SourceHealthChecker::class);
    $checker->shouldReceive('checkScraper')->never();
    $checker->shouldReceive('checkEventSource')
        ->once()
        ->withArgs(fn (EventSource $value) => $value->is($eventSource))
        ->andReturn(['status' => 'unhealthy', 'proposal' => true, 'error' => 'Changed endpoint']);
    app()->instance(SourceHealthChecker::class, $checker);

    $this->artisan('sources:check-health', [
        '--limit' => 10,
        '--include-inactive' => true,
    ])
        ->expectsOutput('Checked 1 sources; 1 unhealthy; 1 verified repair proposals.')
        ->assertSuccessful();
});
