<?php

use App\Models\Article;
use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\ArticleWriter;
use App\Services\Ingestion\Deduplicator;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as M;

uses(Tests\TestCase::class, RefreshDatabase::class);

afterEach(function () {
    M::close();
});

function makeScraper(City $city): Scraper
{
    return Scraper::create([
        'city_id' => $city->id,
        'name' => 'Test Scraper',
        'slug' => 'test-scraper',
        'type' => 'rss',
        'is_enabled' => true,
        'source_url' => 'https://example.com/feed',
        'config' => [],
    ]);
}

it('runs successfully and counts created items', function () {
    $city = City::create(['name' => 'Test City', 'slug' => 'test-city']);
    $scraper = makeScraper($city);

    $items = [[
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Item One',
        'source' => ['source_url' => 'https://example.com/a'],
    ]];

    $fetcher = M::mock(RssFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->withArgs(fn (Scraper $runScraper): bool => $runScraper->is($scraper))->andReturn($items);

    $deduplicator = M::mock(Deduplicator::class);
    $deduplicator->shouldReceive('findExisting')->once()->andReturnNull();

    $writer = M::mock(ArticleWriter::class);
    $writer->shouldReceive('write')->once()->andReturn(new Article);

    $runner = new ScrapeRunner($deduplicator, $writer, $fetcher);

    $run = $runner->run($scraper);

    expect($run->status)->toBe('success')
        ->and($run->items_found)->toBe(1)
        ->and($run->items_created)->toBe(1)
        ->and($run->items_updated)->toBe(0)
        ->and($run->meta['skipped_items'])->toBe(0);
});

it('skips invalid items', function () {
    $city = City::create(['name' => 'Test City', 'slug' => 'test-city']);
    $scraper = makeScraper($city);

    $items = [[
        'city_id' => $city->id,
        // Missing title and source_url
    ]];

    $fetcher = M::mock(RssFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn($items);

    $deduplicator = M::mock(Deduplicator::class);
    $deduplicator->shouldNotReceive('findExisting');

    $writer = M::mock(ArticleWriter::class);
    $writer->shouldNotReceive('write');

    $runner = new ScrapeRunner($deduplicator, $writer, $fetcher);

    $run = $runner->run($scraper);

    expect($run->status)->toBe('success')
        ->and($run->items_found)->toBe(1)
        ->and($run->items_created)->toBe(0)
        ->and($run->items_updated)->toBe(0)
        ->and($run->meta['skipped_items'])->toBe(1);
});

it('updates when deduplicated article is returned', function () {
    $city = City::create(['name' => 'Test City', 'slug' => 'test-city']);
    $scraper = makeScraper($city);

    $existing = Article::create([
        'city_id' => $city->id,
        'title' => 'Existing',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/a',
    ]);

    $items = [[
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Existing',
        'canonical_url' => 'https://example.com/a',
        'source' => ['source_url' => 'https://example.com/a'],
    ]];

    $fetcher = M::mock(RssFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn($items);

    $deduplicator = M::mock(Deduplicator::class);
    $deduplicator->shouldReceive('findExisting')->once()->andReturn($existing);

    $writer = M::mock(ArticleWriter::class);
    $writer->shouldReceive('write')->once()->with($items[0], $existing)->andReturn($existing);

    $runner = new ScrapeRunner($deduplicator, $writer, $fetcher);

    $run = $runner->run($scraper);

    expect($run->status)->toBe('success')
        ->and($run->items_found)->toBe(1)
        ->and($run->items_created)->toBe(0)
        ->and($run->items_updated)->toBe(1)
        ->and($run->meta['skipped_items'])->toBe(0);
});
