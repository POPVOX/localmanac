<?php

use App\Models\City;
use App\Models\EventSource;
use App\Models\Scraper;

test('the coverage migration adds healthy article and event sources for Lawrence and Jackson', function () {
    $lawrence = City::factory()->create(['slug' => 'lawrence-ks']);
    $jackson = City::factory()->create(['slug' => 'jackson-tn']);

    $migration = require database_path('migrations/2026_09_01_000000_expand_lawrence_and_jackson_sources.php');
    $migration->up();
    $migration->up();

    $lawrenceNews = Scraper::query()
        ->where('city_id', $lawrence->id)
        ->where('slug', 'city-of-lawrence-news')
        ->firstOrFail();
    $jacksonNews = Scraper::query()
        ->where('city_id', $jackson->id)
        ->where('slug', 'city-of-jackson-public-notices')
        ->firstOrFail();
    $lawrenceEvents = EventSource::query()
        ->where('city_id', $lawrence->id)
        ->where('source_url', 'https://downtownlawrence.com/calendar/?ical=1')
        ->firstOrFail();
    $jacksonEvents = EventSource::query()
        ->where('city_id', $jackson->id)
        ->where('source_url', 'https://jacksontn.com/calendar/?ical=1')
        ->firstOrFail();

    expect(Scraper::query()->whereIn('city_id', [$lawrence->id, $jackson->id])->count())->toBe(2)
        ->and(EventSource::query()->whereIn('city_id', [$lawrence->id, $jackson->id])->count())->toBe(2)
        ->and($lawrenceNews->type)->toBe('rss')
        ->and($lawrenceNews->config['feed_url'])->toBe('https://lawrenceks.gov/feed/')
        ->and($lawrenceNews->is_enabled)->toBeTrue()
        ->and($lawrenceNews->health_status)->toBe('healthy')
        ->and($jacksonNews->type)->toBe('rss')
        ->and($jacksonNews->config['max_items'])->toBe(50)
        ->and($jacksonNews->is_enabled)->toBeTrue()
        ->and($lawrenceEvents->source_type)->toBe('ics')
        ->and($lawrenceEvents->config['timezone'])->toBe('America/Chicago')
        ->and($lawrenceEvents->is_active)->toBeTrue()
        ->and($jacksonEvents->source_type)->toBe('ics')
        ->and($jacksonEvents->is_active)->toBeTrue();
});

test('the coverage migration updates matching sources instead of duplicating them', function () {
    $lawrence = City::factory()->create(['slug' => 'lawrence-ks']);
    $jackson = City::factory()->create(['slug' => 'jackson-tn']);
    $existingNews = Scraper::create([
        'city_id' => $lawrence->id,
        'name' => 'Old Lawrence Feed',
        'slug' => 'old-lawrence-city-feed',
        'type' => 'rss',
        'source_url' => 'https://lawrenceks.gov/feed/',
        'is_enabled' => false,
    ]);
    $existingEvents = EventSource::factory()->create([
        'city_id' => $jackson->id,
        'source_url' => 'https://jacksontn.com/calendar/?ical=1',
        'is_active' => false,
    ]);

    $migration = require database_path('migrations/2026_09_01_000000_expand_lawrence_and_jackson_sources.php');
    $migration->up();

    expect(Scraper::query()
        ->where('city_id', $lawrence->id)
        ->where('source_url', 'https://lawrenceks.gov/feed/')
        ->count())->toBe(1)
        ->and($existingNews->refresh()->name)->toBe('City of Lawrence News')
        ->and($existingNews->is_enabled)->toBeTrue()
        ->and(EventSource::query()
            ->where('city_id', $jackson->id)
            ->where('source_url', 'https://jacksontn.com/calendar/?ical=1')
            ->count())->toBe(1)
        ->and($existingEvents->refresh()->name)->toBe('Greater Jackson Chamber Events')
        ->and($existingEvents->is_active)->toBeTrue();
});
