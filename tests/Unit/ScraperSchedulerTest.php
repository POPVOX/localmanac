<?php

use App\Models\City;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Ingestion\ScraperScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('honors daily run times in each city timezone', function () {
    $nowUtc = CarbonImmutable::parse('2025-01-02 16:00:00', 'UTC');

    $westCity = City::create([
        'name' => 'West City',
        'slug' => 'west-city',
        'timezone' => 'America/Los_Angeles',
    ]);

    $eastCity = City::create([
        'name' => 'East City',
        'slug' => 'east-city',
        'timezone' => 'America/New_York',
    ]);

    $westScraper = Scraper::create([
        'city_id' => $westCity->id,
        'name' => 'West Daily',
        'slug' => 'west-daily',
        'type' => 'rss',
        'source_url' => 'https://example.com/west',
        'frequency' => 'daily',
        'run_at' => '09:00',
        'is_enabled' => true,
        'config' => [],
    ]);

    $eastScraper = Scraper::create([
        'city_id' => $eastCity->id,
        'name' => 'East Daily',
        'slug' => 'east-daily',
        'type' => 'rss',
        'source_url' => 'https://example.com/east',
        'frequency' => 'daily',
        'run_at' => '09:00',
        'is_enabled' => true,
        'config' => [],
    ]);

    ScraperRun::create([
        'scraper_id' => $westScraper->id,
        'city_id' => $westCity->id,
        'status' => 'success',
        'finished_at' => $nowUtc->subDay(),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    ScraperRun::create([
        'scraper_id' => $eastScraper->id,
        'city_id' => $eastCity->id,
        'status' => 'success',
        'finished_at' => $nowUtc->subDay(),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    $dueScrapers = app(ScraperScheduler::class)->dueScrapers($nowUtc);

    expect($dueScrapers->pluck('id'))
        ->not->toContain($westScraper->id)
        ->toContain($eastScraper->id);
});

it('respects the hourly interval based on last success', function () {
    $nowUtc = CarbonImmutable::parse('2025-01-02 16:00:00', 'UTC');

    $city = City::create([
        'name' => 'Hourly City',
        'slug' => 'hourly-city',
        'timezone' => 'UTC',
    ]);

    $dueScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Hourly Due',
        'slug' => 'hourly-due',
        'type' => 'rss',
        'source_url' => 'https://example.com/hourly-due',
        'frequency' => 'hourly',
        'is_enabled' => true,
        'config' => [],
    ]);

    $notDueScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Hourly Not Due',
        'slug' => 'hourly-not-due',
        'type' => 'rss',
        'source_url' => 'https://example.com/hourly-not-due',
        'frequency' => 'hourly',
        'is_enabled' => true,
        'config' => [],
    ]);

    $neverRunScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Hourly Never',
        'slug' => 'hourly-never',
        'type' => 'rss',
        'source_url' => 'https://example.com/hourly-never',
        'frequency' => 'hourly',
        'is_enabled' => true,
        'config' => [],
    ]);

    ScraperRun::create([
        'scraper_id' => $dueScraper->id,
        'city_id' => $city->id,
        'status' => 'success',
        'finished_at' => $nowUtc->subMinutes(61),
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

    $dueScrapers = app(ScraperScheduler::class)->dueScrapers($nowUtc);

    expect($dueScrapers->pluck('id'))
        ->toContain($dueScraper->id)
        ->toContain($neverRunScraper->id)
        ->not->toContain($notDueScraper->id);
});

it('respects weekly day of week and time gates', function () {
    $nowUtc = CarbonImmutable::parse('2025-01-08 16:00:00', 'UTC');
    $timezone = 'America/Chicago';
    $localNow = $nowUtc->setTimezone($timezone);

    $city = City::create([
        'name' => 'Weekly City',
        'slug' => 'weekly-city',
        'timezone' => $timezone,
    ]);

    $dueScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Weekly Due',
        'slug' => 'weekly-due',
        'type' => 'rss',
        'source_url' => 'https://example.com/weekly-due',
        'frequency' => 'weekly',
        'run_at' => '09:00',
        'run_day_of_week' => $localNow->dayOfWeek,
        'is_enabled' => true,
        'config' => [],
    ]);

    $sameWeekScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Weekly Same Week',
        'slug' => 'weekly-same-week',
        'type' => 'rss',
        'source_url' => 'https://example.com/weekly-same-week',
        'frequency' => 'weekly',
        'run_at' => '09:00',
        'run_day_of_week' => $localNow->dayOfWeek,
        'is_enabled' => true,
        'config' => [],
    ]);

    $wrongDayScraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Weekly Wrong Day',
        'slug' => 'weekly-wrong-day',
        'type' => 'rss',
        'source_url' => 'https://example.com/weekly-wrong-day',
        'frequency' => 'weekly',
        'run_at' => '09:00',
        'run_day_of_week' => ($localNow->dayOfWeek + 1) % 7,
        'is_enabled' => true,
        'config' => [],
    ]);

    $lastWeekLocal = $localNow->subWeek()->setTime(9, 30);
    $thisWeekLocal = $localNow->subDay()->setTime(10, 0);

    ScraperRun::create([
        'scraper_id' => $dueScraper->id,
        'city_id' => $city->id,
        'status' => 'success',
        'finished_at' => $lastWeekLocal->setTimezone('UTC'),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    ScraperRun::create([
        'scraper_id' => $sameWeekScraper->id,
        'city_id' => $city->id,
        'status' => 'success',
        'finished_at' => $thisWeekLocal->setTimezone('UTC'),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    $dueScrapers = app(ScraperScheduler::class)->dueScrapers($nowUtc);

    expect($dueScrapers->pluck('id'))
        ->toContain($dueScraper->id)
        ->not->toContain($sameWeekScraper->id)
        ->not->toContain($wrongDayScraper->id);
});
