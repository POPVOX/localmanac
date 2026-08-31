<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\Assistant\EventSourcePreviewer;
use App\Services\Ingestion\Assistant\ScraperConfigPreviewer;
use App\Services\Ingestion\Assistant\SourceDiscoveryService;
use App\Services\Ingestion\Assistant\SourceHealthChecker;

it('stores only repair proposals that pass a new preview', function () {
    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'City News',
        'slug' => 'city-news',
        'type' => 'html',
        'source_url' => 'https://example.gov/news',
        'config' => ['profile' => 'generic_listing', 'list' => ['link_selector' => '.old a']],
        'is_enabled' => true,
        'frequency' => 'daily',
    ]);

    $scraperPreviewer = Mockery::mock(ScraperConfigPreviewer::class);
    $scraperPreviewer->shouldReceive('preview')->twice()->andReturn(
        ['valid' => false, 'items' => [], 'warnings' => ['No items found.']],
        ['valid' => true, 'items' => [['title' => 'New item']], 'warnings' => []],
    );
    $eventPreviewer = Mockery::mock(EventSourcePreviewer::class);
    $discovery = Mockery::mock(SourceDiscoveryService::class);
    $discovery->shouldReceive('discover')->once()->andReturn([
        'kind' => 'article',
        'type' => 'rss',
        'source_url' => 'https://example.gov/news.rss',
        'config' => ['feed_url' => 'https://example.gov/news.rss'],
        'confidence' => 0.96,
    ]);

    $result = (new SourceHealthChecker($discovery, $scraperPreviewer, $eventPreviewer))->checkScraper($scraper);
    $scraper->refresh();

    expect($result['status'])->toBe('unhealthy')
        ->and($result['proposal'])->toBeTrue()
        ->and($scraper->health_status)->toBe('unhealthy')
        ->and($scraper->repair_proposal['type'])->toBe('rss')
        ->and($scraper->repair_proposal['source_url'])->toBe('https://example.gov/news.rss')
        ->and($scraper->repair_proposal['summary'])->toContain('Verified repair');
});

it('clears old repair state after a successful health preview', function () {
    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Healthy News',
        'slug' => 'healthy-news',
        'type' => 'rss',
        'source_url' => 'https://example.gov/news.rss',
        'config' => ['feed_url' => 'https://example.gov/news.rss'],
        'is_enabled' => true,
        'frequency' => 'daily',
        'health_status' => 'unhealthy',
        'health_error' => 'Old error',
        'repair_proposal' => ['type' => 'html'],
    ]);

    $scraperPreviewer = Mockery::mock(ScraperConfigPreviewer::class);
    $scraperPreviewer->shouldReceive('preview')->once()->andReturn([
        'valid' => true,
        'items' => [['title' => 'Working']],
        'warnings' => [],
    ]);

    $checker = new SourceHealthChecker(
        Mockery::mock(SourceDiscoveryService::class),
        $scraperPreviewer,
        Mockery::mock(EventSourcePreviewer::class),
    );

    $checker->checkScraper($scraper);
    $scraper->refresh();

    expect($scraper->health_status)->toBe('healthy')
        ->and($scraper->health_error)->toBeNull()
        ->and($scraper->repair_proposal)->toBeNull()
        ->and($scraper->health_checked_at)->not->toBeNull();
});
