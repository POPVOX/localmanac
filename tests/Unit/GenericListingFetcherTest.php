<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Ingestion\Fetchers\GenericListingFetcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

function makeGenericListingCity(): City
{
    return City::create([
        'name' => 'Example City',
        'slug' => 'example-city',
    ]);
}

function makeGenericListingScraper(City $city, int $maxLinks = 5): Scraper
{
    return Scraper::create([
        'city_id' => $city->id,
        'name' => 'Generic Listing',
        'slug' => 'generic-listing',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/listing',
        'config' => [
            'profile' => 'generic_listing',
            'best_effort' => true,
            'list' => [
                'link_selector' => '.listing .story-link',
                'link_attr' => 'href',
                'max_links' => $maxLinks,
            ],
            'article' => [
                'content_selector' => '.article-content',
                'remove_selectors' => ['.paywall-note', '.sponsored'],
            ],
        ],
    ]);
}

it('extracts links and ingests article content in best-effort mode', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city);

    Http::fake([
        'https://example.com/listing' => Http::response(
            file_get_contents(base_path('tests/Fixtures/generic_listing_page.html')),
            200
        ),
        'https://example.com/stories/alpha' => Http::response(
            file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html')),
            200
        ),
        'https://example.com/stories/beta' => Http::response(
            file_get_contents(base_path('tests/Fixtures/generic_listing_article_snippet.html')),
            200
        ),
    ]);

    $fetcher = app(GenericListingFetcher::class);
    $items = $fetcher->fetch($scraper);

    expect($items)->toHaveCount(2);

    $full = $items[0];
    expect($full['city_id'])->toBe($city->id)
        ->and($full['scraper_id'])->toBe($scraper->id)
        ->and($full['title'])->toBe('Council meets on zoning')
        ->and($full['canonical_url'])->toBe('https://example.com/stories/alpha')
        ->and($full['published_at'])->toBeInstanceOf(Carbon::class)
        ->and($full['body']['raw_html'])->not->toBeNull()
        ->and($full['body']['cleaned_text'])->toContain('city council convened for a lengthy meeting')
        ->and($full['body']['cleaned_text'])->not->toContain('Subscribe for more')
        ->and($full['content_type'])->toBe('full')
        ->and($full['content_hash'])->not->toBeNull();

    $snippet = $items[1];
    expect($snippet['title'])->toBe('Budget preview')
        ->and($snippet['canonical_url'])->toBe('https://example.com/stories/beta')
        ->and($snippet['summary'])->toBe('Short preview text for the upcoming budget article.')
        ->and($snippet['body']['cleaned_text'])->toContain('finance committee released a short preview')
        ->and($snippet['body']['cleaned_text'])->not->toContain('Advertisement')
        ->and($snippet['content_type'])->toBe('snippet')
        ->and($snippet['source']['source_type'])->toBe('html')
        ->and($snippet['source']['source_url'])->toBe('https://example.com/stories/beta');
});

it('skips profile pages that are mixed into listing links', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 5);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Council meets on zoning</a>
                <a class="story-link" href="https://example.com/staff_name/kami-steinle">Kami Steinle</a>
                <a class="story-link" href="https://example.com/category/news/">News</a>
            </div>
        </body>
    </html>
    HTML;

    $articleHtml = file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html'));
    expect($articleHtml)->toBeString();

    $profileHtml = <<<'HTML'
    <html>
        <head><title>Kami Steinle</title></head>
        <body>
            <main class="article-content">
                <h1>Kami Steinle</h1>
                <p>Assistant News Editor</p>
            </main>
        </body>
    </html>
    HTML;

    Http::fake([
        'https://example.com/listing' => Http::response($listingHtml, 200),
        'https://example.com/stories/alpha' => Http::response((string) $articleHtml, 200),
        'https://example.com/staff_name/kami-steinle' => Http::response($profileHtml, 200),
        'https://example.com/category/news/' => Http::response($profileHtml, 200),
    ]);

    $items = app(GenericListingFetcher::class)->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['canonical_url'])->toBe('https://example.com/stories/alpha')
        ->and($items[0]['title'])->toBe('Council meets on zoning');

    Http::assertSentCount(2);
});

it('falls back to playwright when auto renderer receives a bot challenge page', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 1);

    $challengeHtml = <<<'HTML'
    <html>
        <head><meta name="description" content="px-captcha"></head>
        <body>Before we continue...</body>
    </html>
    HTML;

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Council meets on zoning</a>
            </div>
        </body>
    </html>
    HTML;

    $articleHtml = file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html'));
    expect($articleHtml)->toBeString();

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'http')
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $challengeHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'playwright')
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'http')
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $articleHtml,
            'renderer' => 'http',
        ]);

    $fetcher = new GenericListingFetcher($pageFetcher);
    $items = $fetcher->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('Council meets on zoning')
        ->and($items[0]['source']['source_url'])->toBe('https://example.com/stories/alpha');
});

it('passes configured playwright options to page fetcher', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 1);
    $scraper->update([
        'config' => array_merge($scraper->config ?? [], [
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'storage_state_path' => 'storage/app/playwright/ksn.json',
                    'timeout_ms' => 45000,
                    'wait_selector' => 'main',
                    'user_agent' => 'Mozilla/5.0 Test Agent',
                    'refresh_on_blocked' => false,
                    'refresh_attempts' => 2,
                    'proxy' => [
                        'server' => 'http://proxy.local:8080',
                        'username' => 'user',
                        'password' => 'pass',
                        'bypass' => 'localhost,127.0.0.1',
                    ],
                ],
            ],
        ]),
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Council meets on zoning</a>
            </div>
        </body>
    </html>
    HTML;

    $articleHtml = file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html'));
    expect($articleHtml)->toBeString();

    $expectedOptions = [
        'timeout_ms' => 45000,
        'wait_selector' => 'main',
        'user_agent' => 'Mozilla/5.0 Test Agent',
        'storage_state_path' => 'storage/app/playwright/ksn.json',
        'refresh_on_blocked' => false,
        'refresh_attempts' => 2,
        'proxy' => [
            'server' => 'http://proxy.local:8080',
            'username' => 'user',
            'password' => 'pass',
            'bypass' => 'localhost,127.0.0.1',
        ],
    ];

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'playwright', $expectedOptions)
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'playwright', $expectedOptions)
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $articleHtml,
            'renderer' => 'playwright',
        ]);

    $fetcher = new GenericListingFetcher($pageFetcher);
    $items = $fetcher->fetch($scraper->fresh());

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('Council meets on zoning');
});

it('applies auto-scroll options to listing fetch only', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 1);
    $scraper->update([
        'config' => array_merge($scraper->config ?? [], [
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'storage_state_path' => 'storage/app/playwright/ksn.json',
                    'timeout_ms' => 45000,
                    'wait_selector' => 'main',
                    'auto_scroll' => true,
                    'max_scroll_steps' => 12,
                    'scroll_pause_ms' => 700,
                ],
            ],
        ]),
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Council meets on zoning</a>
            </div>
        </body>
    </html>
    HTML;

    $articleHtml = file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html'));
    expect($articleHtml)->toBeString();

    $listingOptions = [
        'timeout_ms' => 45000,
        'wait_selector' => 'main',
        'storage_state_path' => 'storage/app/playwright/ksn.json',
        'auto_scroll' => true,
        'max_scroll_steps' => 12,
        'scroll_pause_ms' => 700,
    ];

    $articleOptions = [
        'timeout_ms' => 45000,
        'wait_selector' => 'main',
        'storage_state_path' => 'storage/app/playwright/ksn.json',
    ];

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'playwright', $listingOptions)
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'playwright', $articleOptions)
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $articleHtml,
            'renderer' => 'playwright',
        ]);

    $fetcher = new GenericListingFetcher($pageFetcher);
    $items = $fetcher->fetch($scraper->fresh());

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('Council meets on zoning');
});

it('collects links across paginated listing pages when configured', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 3);
    $scraper->update([
        'config' => array_merge($scraper->config ?? [], [
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'timeout_ms' => 45000,
                    'wait_selector' => 'main',
                ],
            ],
            'list' => [
                'link_selector' => '.listing .story-link',
                'link_attr' => 'href',
                'max_links' => 3,
                'max_pages' => 2,
                'pagination_selector' => '.pagination .next',
                'pagination_attr' => 'href',
            ],
        ]),
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Alpha</a>
            </div>
            <nav class="pagination">
                <a class="next" href="https://example.com/listing/page/2">Next</a>
            </nav>
        </body>
    </html>
    HTML;

    $page2Html = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/beta">Beta</a>
                <a class="story-link" href="https://example.com/stories/gamma">Gamma</a>
            </div>
        </body>
    </html>
    HTML;

    $fullArticle = file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html'));
    $snippetArticle = file_get_contents(base_path('tests/Fixtures/generic_listing_article_snippet.html'));
    $gammaArticle = <<<'HTML'
    <html>
        <head>
            <title>Gamma Story</title>
            <meta property="og:title" content="Gamma Story">
            <link rel="canonical" href="https://example.com/stories/gamma">
            <meta name="description" content="Gamma summary">
        </head>
        <body>
            <main class="article-content">
                <p>Gamma body text with enough content to create an item.</p>
            </main>
        </body>
    </html>
    HTML;

    expect($fullArticle)->toBeString()
        ->and($snippetArticle)->toBeString();

    $options = [
        'timeout_ms' => 45000,
        'wait_selector' => 'main',
    ];

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing/page/2', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/listing/page/2',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $page2Html,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $fullArticle,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/beta', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/beta',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $snippetArticle,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/gamma', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/gamma',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $gammaArticle,
            'renderer' => 'playwright',
        ]);

    $items = (new GenericListingFetcher($pageFetcher))->fetch($scraper->fresh());

    expect($items)->toHaveCount(3)
        ->and($items[0]['canonical_url'])->toBe('https://example.com/stories/alpha')
        ->and($items[1]['canonical_url'])->toBe('https://example.com/stories/beta')
        ->and($items[2]['canonical_url'])->toBe('https://example.com/stories/gamma');
});

it('discovers pagination URLs embedded in script payloads when anchors are absent', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 2);
    $scraper->update([
        'config' => array_merge($scraper->config ?? [], [
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'timeout_ms' => 45000,
                    'wait_selector' => 'main',
                ],
            ],
            'list' => [
                'link_selector' => '.listing .story-link',
                'link_attr' => 'href',
                'max_links' => 2,
                'max_pages' => 2,
                'pagination_selector' => 'a[href*="/page/"]',
                'pagination_attr' => 'href',
            ],
        ]),
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Alpha</a>
            </div>
            <script type="application/json">
                {"pagination":"https://example.com/listing/page/2"}
            </script>
        </body>
    </html>
    HTML;

    $page2Html = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/beta">Beta</a>
            </div>
        </body>
    </html>
    HTML;

    $fullArticle = file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html'));
    $snippetArticle = file_get_contents(base_path('tests/Fixtures/generic_listing_article_snippet.html'));

    expect($fullArticle)->toBeString()
        ->and($snippetArticle)->toBeString();

    $options = [
        'timeout_ms' => 45000,
        'wait_selector' => 'main',
    ];

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing/page/2', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/listing/page/2',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $page2Html,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $fullArticle,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/beta', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/beta',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $snippetArticle,
            'renderer' => 'playwright',
        ]);

    $items = (new GenericListingFetcher($pageFetcher))->fetch($scraper->fresh());

    expect($items)->toHaveCount(2)
        ->and($items[0]['canonical_url'])->toBe('https://example.com/stories/alpha')
        ->and($items[1]['canonical_url'])->toBe('https://example.com/stories/beta');
});

it('follows chained script-discovered pagination paths across listing pages', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 3);
    $scraper->update([
        'config' => array_merge($scraper->config ?? [], [
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'timeout_ms' => 45000,
                    'wait_selector' => 'main',
                ],
            ],
            'list' => [
                'link_selector' => '.listing .story-link',
                'link_attr' => 'href',
                'max_links' => 3,
                'max_pages' => 3,
                'pagination_selector' => 'a[href*="/page/"]',
                'pagination_attr' => 'href',
            ],
        ]),
    ]);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Alpha</a>
            </div>
            <script>{"next":"https://example.com/listing/page/2"}</script>
        </body>
    </html>
    HTML;

    $page2Html = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/beta">Beta</a>
            </div>
            <script>{"next":"https://example.com/listing/page/3"}</script>
        </body>
    </html>
    HTML;

    $page3Html = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/gamma">Gamma</a>
            </div>
        </body>
    </html>
    HTML;

    $fullArticle = file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html'));
    $snippetArticle = file_get_contents(base_path('tests/Fixtures/generic_listing_article_snippet.html'));
    $gammaArticle = <<<'HTML'
    <html>
        <head>
            <title>Gamma Story</title>
            <meta property="og:title" content="Gamma Story">
            <link rel="canonical" href="https://example.com/stories/gamma">
        </head>
        <body>
            <main class="article-content">
                <p>Gamma body text.</p>
            </main>
        </body>
    </html>
    HTML;

    expect($fullArticle)->toBeString()
        ->and($snippetArticle)->toBeString();

    $options = [
        'timeout_ms' => 45000,
        'wait_selector' => 'main',
    ];

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing/page/2', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/listing/page/2',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $page2Html,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing/page/3', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/listing/page/3',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $page3Html,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $fullArticle,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/beta', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/beta',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $snippetArticle,
            'renderer' => 'playwright',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/gamma', 'playwright', $options)
        ->andReturn([
            'url' => 'https://example.com/stories/gamma',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $gammaArticle,
            'renderer' => 'playwright',
        ]);

    $items = (new GenericListingFetcher($pageFetcher))->fetch($scraper->fresh());

    expect($items)->toHaveCount(3)
        ->and($items[0]['canonical_url'])->toBe('https://example.com/stories/alpha')
        ->and($items[1]['canonical_url'])->toBe('https://example.com/stories/beta')
        ->and($items[2]['canonical_url'])->toBe('https://example.com/stories/gamma');
});

it('surfaces anti-bot blocking when playwright fallback returns a challenge page', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 1);

    $challengeHtml = <<<'HTML'
    <html>
        <head><meta name="description" content="px-captcha"></head>
        <body>Before we continue...</body>
    </html>
    HTML;

    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'http')
        ->andReturnNull();
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/listing', 'playwright')
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $challengeHtml,
            'renderer' => 'playwright',
        ]);

    $fetcher = new GenericListingFetcher($pageFetcher);

    expect(fn () => $fetcher->fetch($scraper))
        ->toThrow(InvalidArgumentException::class, 'Listing page is blocked by anti-bot protection.');
});

it('surfaces anti-bot blocking when article pages remain in javascript challenge', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city, maxLinks: 1);

    $listingHtml = <<<'HTML'
    <html>
        <body>
            <div class="listing">
                <a class="story-link" href="https://example.com/stories/alpha">Council meets on zoning</a>
            </div>
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
        ->with('https://example.com/listing', 'http')
        ->andReturn([
            'url' => 'https://example.com/listing',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $listingHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'http')
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $javascriptChallengeHtml,
            'renderer' => 'http',
        ]);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/stories/alpha', 'playwright')
        ->andReturn([
            'url' => 'https://example.com/stories/alpha',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $javascriptChallengeHtml,
            'renderer' => 'playwright',
        ]);

    $fetcher = new GenericListingFetcher($pageFetcher);

    expect(fn () => $fetcher->fetch($scraper))
        ->toThrow(InvalidArgumentException::class, 'Article pages are blocked by anti-bot protection.');
});
