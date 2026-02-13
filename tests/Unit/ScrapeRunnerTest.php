<?php

use App\Models\Article;
use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\ArticleWriter;
use App\Services\Ingestion\Deduplicator;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\PostgresSequenceSynchronizer;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Database\UniqueConstraintViolationException;
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

function makePartialScrapeRunner(PostgresSequenceSynchronizer $synchronizer): ScrapeRunner
{
    return M::mock(ScrapeRunner::class, [
        M::mock(Deduplicator::class),
        M::mock(ArticleWriter::class),
        M::mock(RssFetcher::class),
        $synchronizer,
    ])->makePartial()->shouldAllowMockingProtectedMethods();
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

it('retries scraper run creation once after recoverable sequence drift is repaired', function () {
    $city = City::create(['name' => 'Test City', 'slug' => 'test-city']);
    $scraper = makeScraper($city);

    $stored = new \App\Models\ScraperRun([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'queued',
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
        'meta' => [],
    ]);
    $stored->id = 17;

    $recoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "scraper_runs"',
        [],
        new RuntimeException('duplicate key value violates unique constraint "scraper_runs_pkey"')
    );

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(['scraper_runs'])
        ->andReturn(true);

    $runner = makePartialScrapeRunner($synchronizer);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andThrow($recoverableViolation);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andReturn($stored);

    $result = $runner->createRun($scraper);

    expect($result)->toBe($stored)
        ->and($result->id)->toBe(17);
});

it('does not retry scraper run creation on non-recoverable unique constraints', function () {
    $city = City::create(['name' => 'Test City', 'slug' => 'test-city']);
    $scraper = makeScraper($city);

    $nonRecoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "scraper_runs"',
        [],
        new RuntimeException('duplicate key value violates unique constraint "scraper_runs_scraper_id_created_at_unique"')
    );

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')->never();

    $runner = makePartialScrapeRunner($synchronizer);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andThrow($nonRecoverableViolation);

    expect(fn () => $runner->createRun($scraper))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('fails scraper run creation when sequence drift is detected but not repaired', function () {
    $city = City::create(['name' => 'Test City', 'slug' => 'test-city']);
    $scraper = makeScraper($city);

    $recoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "scraper_runs"',
        [],
        new RuntimeException('duplicate key value violates unique constraint "scraper_runs_pkey"')
    );

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(['scraper_runs'])
        ->andReturn(false);

    $runner = makePartialScrapeRunner($synchronizer);
    $runner->shouldReceive('persistRun')
        ->once()
        ->andThrow($recoverableViolation);

    expect(fn () => $runner->createRun($scraper))
        ->toThrow(UniqueConstraintViolationException::class);
});
