<?php

use App\Models\City;
use App\Models\EventIngestionRun;
use App\Models\EventSource;
use App\Models\Scraper;
use App\Models\ScraperRun;

test('the source repair migration replaces legacy Documenters scrapers and disables incomplete event sources', function () {
    $wichita = City::factory()->create(['slug' => 'wichita']);
    $jackson = City::factory()->create(['slug' => 'jackson-tn']);
    $legacy = Scraper::create([
        'city_id' => $wichita->id,
        'name' => 'Legacy Documenters Board',
        'slug' => 'legacy-documenters-board',
        'type' => 'html',
        'source_url' => 'https://wichitadocumenters.org/meetings/legacy-board',
        'is_enabled' => true,
    ]);
    $legacyRun = ScraperRun::create([
        'scraper_id' => $legacy->id,
        'city_id' => $wichita->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);
    $incomplete = EventSource::factory()->create([
        'city_id' => $jackson->id,
        'source_type' => 'html',
        'source_url' => 'https://example.com/events',
        'config' => [],
        'is_active' => true,
    ]);
    $incompleteRun = EventIngestionRun::factory()->create([
        'event_source_id' => $incomplete->id,
        'status' => 'queued',
    ]);
    $configured = EventSource::factory()->create([
        'city_id' => $wichita->id,
        'source_type' => 'html',
        'source_url' => 'https://example.com/configured-events',
        'config' => [
            'list' => ['item_selector' => '.event'],
        ],
        'is_active' => true,
    ]);

    $migration = require database_path('migrations/2026_08_31_000000_retire_broken_scraper_sources.php');
    $migration->up();

    $replacement = Scraper::query()
        ->where('city_id', $wichita->id)
        ->where('slug', 'wichita-documenters-reporting')
        ->first();

    expect($legacy->refresh()->is_enabled)->toBeFalse()
        ->and($legacyRun->refresh()->status)->toBe('failed')
        ->and($replacement)->not->toBeNull()
        ->and($replacement?->is_enabled)->toBeTrue()
        ->and($replacement?->type)->toBe('rss')
        ->and($replacement?->source_url)->toBe('https://wichita-ks.documenters.org/feed/rss/')
        ->and($incomplete->refresh()->is_active)->toBeFalse()
        ->and($incompleteRun->refresh()->status)->toBe('failed')
        ->and($configured->refresh()->is_active)->toBeTrue();
});
