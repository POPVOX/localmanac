<?php

use App\Services\Ingestion\Assistant\SourceDiscoveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('scraper-assistant.ai.refine_enabled', false);
});

it('discovers an article feed from a public news page', function () {
    Http::fake([
        'https://lawrence.example.gov/news' => Http::response(<<<'HTML'
            <!doctype html>
            <html><head>
                <title>Lawrence City News</title>
                <link rel="alternate" type="application/rss+xml" href="/news/feed.xml">
            </head><body><main><h1>Latest news</h1></main></body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://lawrence.example.gov/news');

    expect($result['kind'])->toBe('article')
        ->and($result['type'])->toBe('rss')
        ->and($result['source_url'])->toBe('https://lawrence.example.gov/news/feed.xml')
        ->and($result['config']['feed_url'])->toBe('https://lawrence.example.gov/news/feed.xml')
        ->and($result['endpoints'])->toHaveCount(1);
});

it('classifies an event page and drafts reusable html calendar selectors', function () {
    Http::fake([
        'https://jackson.example.gov/calendar' => Http::response(<<<'HTML'
            <!doctype html>
            <html><head><title>Jackson Community Calendar</title>
            <script type="application/ld+json">{"@type":"Event","name":"Planning meeting"}</script>
            </head><body><main>
                <article class="event"><h2><a href="/events/1">Planning meeting</a></h2><time datetime="2026-09-10T18:00:00-05:00">September 10</time></article>
                <article class="event"><h2><a href="/events/2">Library board</a></h2><time datetime="2026-09-12T10:00:00-05:00">September 12</time></article>
            </main></body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://jackson.example.gov/calendar');

    expect($result['kind'])->toBe('event')
        ->and($result['type'])->toBe('html')
        ->and($result['config']['profile'])->toBe('generic_html_list')
        ->and($result['config']['list']['item_selector'])->toBe('article.event')
        ->and($result['config']['list']['datetime_selector'])->toBe('time[datetime]');
});

it('recognizes direct ics and json event endpoints', function () {
    $ics = "BEGIN:VCALENDAR\r\nX-WR-CALNAME:City Meetings\r\nBEGIN:VEVENT\r\nSUMMARY:Council\r\nDTSTART:20260910T180000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    Http::fake([
        'https://example.gov/calendar.ics' => Http::response($ics, 200, ['Content-Type' => 'text/calendar']),
        'https://example.gov/api/events.json' => Http::response([
            'events' => [[
                'name' => 'Council meeting',
                'startDate' => '2026-09-10T18:00:00-05:00',
            ]],
        ], 200),
    ]);

    $icsResult = app(SourceDiscoveryService::class)->discover('https://example.gov/calendar.ics');
    $jsonResult = app(SourceDiscoveryService::class)->discover('https://example.gov/api/events.json');

    expect($icsResult['kind'])->toBe('event')
        ->and($icsResult['type'])->toBe('ics')
        ->and($icsResult['name'])->toBe('City Meetings')
        ->and($jsonResult['kind'])->toBe('event')
        ->and($jsonResult['type'])->toBe('json_api')
        ->and(data_get($jsonResult, 'config.json.root_path'))->toBe('events')
        ->and(data_get($jsonResult, 'config.json.mapping.starts_at'))->toBe('startDate');
});

it('inspects a discovered json endpoint before drafting its event mapping', function () {
    Http::fake([
        'https://example.gov/calendar' => Http::response(<<<'HTML'
            <!doctype html><html><head><title>Community Calendar</title></head><body>
            <a href="/api/calendar/events.json" type="application/json">Calendar data</a>
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
        'https://example.gov/api/calendar/events.json' => Http::response([
            'results' => [[
                'summary' => 'Council meeting',
                'start' => ['dateTime' => '2026-09-10T18:00:00-05:00'],
                'venue' => ['name' => 'City Hall', 'address' => '100 Main St'],
            ]],
        ], 200),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://example.gov/calendar');

    expect($result['kind'])->toBe('event')
        ->and($result['type'])->toBe('json_api')
        ->and($result['source_url'])->toBe('https://example.gov/api/calendar/events.json')
        ->and(data_get($result, 'config.json.root_path'))->toBe('results')
        ->and(data_get($result, 'config.json.mapping.title'))->toBe('summary')
        ->and(data_get($result, 'config.json.mapping.starts_at'))->toBe('start.dateTime')
        ->and(data_get($result, 'config.json.mapping.location_name'))->toBe('venue.name')
        ->and(data_get($result, 'config.json.mapping.location_address'))->toBe('venue.address');
});
