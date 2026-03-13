<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Ingestion\Fetchers\DocumentersFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('falls back to playwright for documenters listing pages blocked by bot challenge', function () {
    $city = City::create([
        'name' => 'Example City',
        'slug' => 'example-city',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Documenters',
        'slug' => 'documenters',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/documenters',
        'config' => [
            'profile' => 'wichitadocumenters',
            'list' => [
                'link_selector' => '.story-link',
                'link_attr' => 'href',
                'max_links' => 1,
            ],
        ],
    ]);

    $challengeHtml = <<<'HTML'
    <html>
        <head><meta name="description" content="px-captcha"></head>
        <body>Before we continue...</body>
    </html>
    HTML;

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <a class="story-link" href="https://docs.google.com/document/d/demo/pub">Meeting Notes</a>
        </body>
    </html>
    HTML;

    $docHtml = <<<'HTML'
    <html>
        <body>
            <div id="contents">
                <h1>Neighborhood Advisory Board</h1>
                <p>Date: March 1, 2026</p>
                <p>Members reviewed transit plans and approved minutes.</p>
            </div>
        </body>
    </html>
    HTML;

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'http')
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $challengeHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'playwright')
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://docs.google.com/document/d/demo/pub', 'http')
        ->andReturn([
            'url' => 'https://docs.google.com/document/d/demo/pub',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $docHtml,
            'renderer' => 'http',
        ]);

    $items = (new DocumentersFetcher($pageFetcher))->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toContain('Notes')
        ->and($items[0]['source']['source_url'])->toBe('https://docs.google.com/document/d/demo/pub')
        ->and($items[0]['body']['cleaned_text'])->toContain('transit plans');
});

it('passes configured playwright options for documenters profile', function () {
    $city = City::create([
        'name' => 'Another City',
        'slug' => 'another-city',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Documenters Configured',
        'slug' => 'documenters-configured',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/documenters',
        'config' => [
            'profile' => 'wichitadocumenters',
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'storage_state_path' => 'storage/app/playwright/documenters.json',
                    'timeout_ms' => 55000,
                    'wait_selector' => '.story-link',
                    'user_agent' => 'Mozilla/5.0 Documenters',
                    'refresh_on_blocked' => false,
                    'refresh_attempts' => 4,
                ],
            ],
            'list' => [
                'link_selector' => '.story-link',
                'link_attr' => 'href',
                'max_links' => 1,
            ],
        ],
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <a class="story-link" href="https://docs.google.com/document/d/demo/pub">Meeting Notes</a>
        </body>
    </html>
    HTML;

    $docHtml = <<<'HTML'
    <html>
        <body>
            <div id="contents">
                <h1>Neighborhood Advisory Board</h1>
                <p>Members reviewed transit plans and approved minutes.</p>
            </div>
        </body>
    </html>
    HTML;

    $expectedOptions = [
        'timeout_ms' => 55000,
        'wait_selector' => '.story-link',
        'user_agent' => 'Mozilla/5.0 Documenters',
        'storage_state_path' => 'storage/app/playwright/documenters.json',
        'refresh_on_blocked' => false,
        'refresh_attempts' => 4,
    ];

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'playwright', $expectedOptions)
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://docs.google.com/document/d/demo/pub', 'playwright', $expectedOptions)
        ->andReturn([
            'url' => 'https://docs.google.com/document/d/demo/pub',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $docHtml,
            'renderer' => 'playwright',
        ]);

    $items = (new DocumentersFetcher($pageFetcher))->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['source']['source_url'])->toBe('https://docs.google.com/document/d/demo/pub');
});

it('extracts abbreviated documenters dates as published_at', function () {
    $city = City::create([
        'name' => 'Abbreviated City',
        'slug' => 'abbreviated-city',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Documenters Abbreviated Date',
        'slug' => 'documenters-abbreviated-date',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/documenters',
        'config' => [
            'profile' => 'wichitadocumenters',
            'list' => [
                'link_selector' => '.story-link',
                'link_attr' => 'href',
                'max_links' => 1,
            ],
        ],
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <a class="story-link" href="https://docs.google.com/document/d/demo/pub">Meeting Notes</a>
        </body>
    </html>
    HTML;

    $docHtml = <<<'HTML'
    <html>
        <body>
            <div id="contents">
                <h1>City Council</h1>
                <p>Date: Sept. 16, 2025</p>
                <p>Budget discussion and public comment.</p>
            </div>
        </body>
    </html>
    HTML;

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'http')
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://docs.google.com/document/d/demo/pub', 'http')
        ->andReturn([
            'url' => 'https://docs.google.com/document/d/demo/pub',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $docHtml,
            'renderer' => 'http',
        ]);

    $items = (new DocumentersFetcher($pageFetcher))->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['published_at']?->toDateString())->toBe('2025-09-16');
});

it('falls back to the document title banner date when the date label is missing', function () {
    $city = City::create([
        'name' => 'Title Date City',
        'slug' => 'title-date-city',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Documenters Title Date',
        'slug' => 'documenters-title-date',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/documenters',
        'config' => [
            'profile' => 'wichitadocumenters',
            'list' => [
                'link_selector' => '.story-link',
                'link_attr' => 'href',
                'max_links' => 1,
            ],
        ],
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <a class="story-link" href="https://docs.google.com/document/d/demo/pub">Meeting Notes</a>
        </body>
    </html>
    HTML;

    $docHtml = <<<'HTML'
    <html>
        <head>
            <title>Wichita City - Affordable Housing Review Board - Affordable Housing Review Board Meeting 06/24/2024</title>
        </head>
        <body>
            <div id="title">Wichita City - Affordable Housing Review Board - Affordable Housing Review Board Meeting 06/24/2024</div>
            <div id="contents">
                <h1>Affordable Housing Review Board Meeting</h1>
                <p>The June meeting was canceled and moved to July 29, 2024.</p>
            </div>
        </body>
    </html>
    HTML;

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'http')
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://docs.google.com/document/d/demo/pub', 'http')
        ->andReturn([
            'url' => 'https://docs.google.com/document/d/demo/pub',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $docHtml,
            'renderer' => 'http',
        ]);

    $items = (new DocumentersFetcher($pageFetcher))->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['published_at']?->toDateString())->toBe('2024-06-24');
});

it('applies auto-scroll options to documenters listing fetch only', function () {
    $city = City::create([
        'name' => 'Documenters Scroll City',
        'slug' => 'documenters-scroll-city',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Documenters Scroll',
        'slug' => 'documenters-scroll',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/documenters',
        'config' => [
            'profile' => 'wichitadocumenters',
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'storage_state_path' => 'storage/app/playwright/documenters.json',
                    'timeout_ms' => 55000,
                    'wait_selector' => '.story-link',
                    'auto_scroll' => true,
                    'max_scroll_steps' => 9,
                    'scroll_pause_ms' => 650,
                ],
            ],
            'list' => [
                'link_selector' => '.story-link',
                'link_attr' => 'href',
                'max_links' => 1,
            ],
        ],
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <a class="story-link" href="https://docs.google.com/document/d/demo/pub">Meeting Notes</a>
        </body>
    </html>
    HTML;

    $docHtml = <<<'HTML'
    <html>
        <body>
            <div id="contents">
                <h1>Neighborhood Advisory Board</h1>
                <p>Members reviewed transit plans and approved minutes.</p>
            </div>
        </body>
    </html>
    HTML;

    $listingOptions = [
        'timeout_ms' => 55000,
        'wait_selector' => '.story-link',
        'storage_state_path' => 'storage/app/playwright/documenters.json',
        'auto_scroll' => true,
        'max_scroll_steps' => 9,
        'scroll_pause_ms' => 650,
    ];

    $detailOptions = [
        'timeout_ms' => 55000,
        'wait_selector' => '.story-link',
        'storage_state_path' => 'storage/app/playwright/documenters.json',
    ];

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'playwright', $listingOptions)
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://docs.google.com/document/d/demo/pub', 'playwright', $detailOptions)
        ->andReturn([
            'url' => 'https://docs.google.com/document/d/demo/pub',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $docHtml,
            'renderer' => 'playwright',
        ]);

    $items = (new DocumentersFetcher($pageFetcher))->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['source']['source_url'])->toBe('https://docs.google.com/document/d/demo/pub');
});

it('surfaces anti-bot blocking when playwright fallback stays blocked', function () {
    $city = City::create([
        'name' => 'Blocked City',
        'slug' => 'blocked-city',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Documenters Blocked',
        'slug' => 'documenters-blocked',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/documenters',
        'config' => [
            'profile' => 'wichitadocumenters',
            'list' => [
                'link_selector' => '.story-link',
                'link_attr' => 'href',
                'max_links' => 1,
            ],
        ],
    ]);

    $challengeHtml = <<<'HTML'
    <html>
        <head><meta name="description" content="px-captcha"></head>
        <body>Before we continue...</body>
    </html>
    HTML;

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'http')
        ->andReturnNull();
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'playwright')
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $challengeHtml,
            'renderer' => 'playwright',
        ]);

    $fetcher = new DocumentersFetcher($pageFetcher);

    expect(fn () => $fetcher->fetch($scraper))
        ->toThrow(InvalidArgumentException::class, 'Listing page is blocked by anti-bot protection.');
});

it('surfaces anti-bot blocking when detail pages remain in javascript challenge', function () {
    $city = City::create([
        'name' => 'Challenge City',
        'slug' => 'challenge-city',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Documenters Challenge',
        'slug' => 'documenters-challenge',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/documenters',
        'config' => [
            'profile' => 'wichitadocumenters',
            'list' => [
                'link_selector' => '.story-link',
                'link_attr' => 'href',
                'max_links' => 1,
            ],
        ],
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <a class="story-link" href="https://docs.google.com/document/d/demo/pub">Meeting Notes</a>
        </body>
    </html>
    HTML;

    $javascriptChallengeHtml = <<<'HTML'
    <html>
        <body>
            <h1>Checking your browser...</h1>
            <p>Javascript required</p>
        </body>
    </html>
    HTML;

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/documenters', 'http')
        ->andReturn([
            'url' => 'https://example.com/documenters',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://docs.google.com/document/d/demo/pub', 'http')
        ->andReturn([
            'url' => 'https://docs.google.com/document/d/demo/pub',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $javascriptChallengeHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://docs.google.com/document/d/demo/pub', 'playwright')
        ->andReturn([
            'url' => 'https://docs.google.com/document/d/demo/pub',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $javascriptChallengeHtml,
            'renderer' => 'playwright',
        ]);

    $fetcher = new DocumentersFetcher($pageFetcher);

    expect(fn () => $fetcher->fetch($scraper))
        ->toThrow(InvalidArgumentException::class, 'Detail pages are blocked by anti-bot protection.');
});
