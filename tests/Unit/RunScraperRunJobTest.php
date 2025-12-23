<?php

use App\Jobs\RunScraperRun;
use App\Models\City;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as M;

uses(Tests\TestCase::class, RefreshDatabase::class);

afterEach(function () {
    M::close();
});

it('processes a queued run through the scrape runner', function () {
    $city = City::create(['name' => 'Job City', 'slug' => 'job-city']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Job Scraper',
        'slug' => 'job-scraper',
        'type' => 'rss',
        'is_enabled' => true,
        'source_url' => 'https://example.com/feed',
        'config' => [],
    ]);

    $run = ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'queued',
    ]);

    $runner = M::mock(ScrapeRunner::class);
    $runner->shouldReceive('runExisting')
        ->once()
        ->withArgs(fn (ScraperRun $jobRun): bool => $jobRun->is($run));

    (new RunScraperRun($run->id))->handle($runner);
});

it('marks the run as failed when the job fails unexpectedly', function () {
    $city = City::create(['name' => 'Job Fail City', 'slug' => 'job-fail-city']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Job Fail Scraper',
        'slug' => 'job-fail-scraper',
        'type' => 'rss',
        'is_enabled' => true,
        'source_url' => 'https://example.com/feed',
        'config' => [],
    ]);

    $run = ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'queued',
    ]);

    $job = new RunScraperRun($run->id);

    $job->failed(new \RuntimeException('Job failure'));

    $run->refresh();

    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toBe('Job failure')
        ->and($run->finished_at)->not->toBeNull();
});
