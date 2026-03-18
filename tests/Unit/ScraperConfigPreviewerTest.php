<?php

use App\Services\Ingestion\Assistant\ScraperConfigPreviewer;
use App\Services\Ingestion\Fetchers\DocumentersFetcher;
use App\Services\Ingestion\Fetchers\GenericListingFetcher;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\Fetchers\WichitaArchivePdfListFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns preview items for generic listing config', function () {
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

    $result = app(ScraperConfigPreviewer::class)->preview(
        cityId: 1,
        organizationId: null,
        type: 'html',
        sourceUrl: 'https://example.com/listing',
        config: [
            'profile' => 'generic_listing',
            'best_effort' => true,
            'list' => [
                'link_selector' => '.listing .story-link',
                'link_attr' => 'href',
                'max_links' => 5,
            ],
            'article' => [
                'content_selector' => '.article-content',
                'remove_selectors' => ['.paywall-note', '.sponsored'],
            ],
        ],
    );

    expect($result['valid'])->toBeTrue()
        ->and($result['items'])->not->toBeEmpty()
        ->and($result['items'][0]['title'])->toBe('Council meets on zoning')
        ->and($result['items'][0]['source_url'])->toBe('https://example.com/stories/alpha');
});

it('returns archive preview rows for wichita archive profile', function () {
    Http::fake([
        'https://www.wichita.gov/Archive.aspx?AMID=102' => Http::response(
            <<<'HTML'
            <html>
                <body>
                    <table summary="Archive Details">
                        <tr>
                            <td><img src="common/images/iconpdf.gif" alt="Agenda"></td>
                            <td><a href="/Archive.aspx?ADID=101">Agenda Packet</a></td>
                        </tr>
                    </table>
                </body>
            </html>
            HTML,
            200
        ),
    ]);

    $result = app(ScraperConfigPreviewer::class)->preview(
        cityId: 1,
        organizationId: null,
        type: 'html',
        sourceUrl: 'https://www.wichita.gov/Archive.aspx?AMID=102',
        config: [
            'profile' => 'wichita_archive_pdf_list',
            'list' => [
                'href_contains' => 'Archive.aspx?ADID=',
                'max_links' => 25,
            ],
            'pdf' => [
                'extract' => true,
            ],
        ],
    );

    expect($result['valid'])->toBeTrue()
        ->and($result['items'][0]['title'])->toBe('Agenda Packet')
        ->and($result['items'][0]['source_url'])->toBe('https://www.wichita.gov/Archive.aspx?ADID=101');
});

it('caps generic listing preview workload config before fetching', function () {
    config()->set('scraper-assistant.preview.generic_listing.max_links', 4);
    config()->set('scraper-assistant.preview.generic_listing.max_pages', 1);
    config()->set('scraper-assistant.preview.generic_listing.playwright_timeout_ms', 12000);
    config()->set('scraper-assistant.preview.generic_listing.playwright_refresh_attempts', 1);
    config()->set('scraper-assistant.preview.generic_listing.playwright_max_scroll_steps', 3);
    config()->set('scraper-assistant.preview.generic_listing.playwright_scroll_pause_ms', 250);

    $rssFetcher = Mockery::mock(RssFetcher::class);
    $rssFetcher->shouldReceive('fetch')->never();

    $documentersFetcher = Mockery::mock(DocumentersFetcher::class);
    $documentersFetcher->shouldReceive('fetch')->never();

    $archiveFetcher = Mockery::mock(WichitaArchivePdfListFetcher::class);
    $archiveFetcher->shouldReceive('fetch')->never();

    $genericFetcher = Mockery::mock(GenericListingFetcher::class);
    $genericFetcher->shouldReceive('fetch')
        ->once()
        ->withArgs(function ($scraper): bool {
            return Arr::get($scraper->config, 'list.max_links') === 4
                && Arr::get($scraper->config, 'list.max_pages') === 1
                && Arr::get($scraper->config, 'fetch.playwright.timeout_ms') === 12000
                && Arr::get($scraper->config, 'fetch.playwright.refresh_attempts') === 1
                && Arr::get($scraper->config, 'fetch.playwright.max_scroll_steps') === 3
                && Arr::get($scraper->config, 'fetch.playwright.scroll_pause_ms') === 250;
        })
        ->andReturn([
            [
                'title' => 'Preview Story',
                'canonical_url' => 'https://example.com/stories/preview',
                'content_type' => 'full',
                'published_at' => '2026-03-04T10:00:00+00:00',
            ],
        ]);

    $previewer = new ScraperConfigPreviewer(
        rssFetcher: $rssFetcher,
        documentersFetcher: $documentersFetcher,
        genericListingFetcher: $genericFetcher,
        wichitaArchivePdfListFetcher: $archiveFetcher,
    );

    $result = $previewer->preview(
        cityId: 1,
        organizationId: null,
        type: 'html',
        sourceUrl: 'https://example.com/listing',
        config: [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => 'article a',
                'link_attr' => 'href',
                'max_links' => 25,
                'max_pages' => 5,
            ],
            'article' => [
                'content_selector' => 'article',
                'remove_selectors' => ['script', 'style'],
            ],
            'fetch' => [
                'playwright' => [
                    'timeout_ms' => 45000,
                    'refresh_attempts' => 3,
                    'max_scroll_steps' => 12,
                    'scroll_pause_ms' => 1000,
                ],
            ],
            'best_effort' => true,
        ],
    );

    expect($result['valid'])->toBeTrue()
        ->and($result['items'][0]['title'])->toBe('Preview Story');
});

it('does not cap non generic listing preview configs', function () {
    $rssFetcher = Mockery::mock(RssFetcher::class);
    $rssFetcher->shouldReceive('fetch')->never();

    $genericFetcher = Mockery::mock(GenericListingFetcher::class);
    $genericFetcher->shouldReceive('fetch')->never();

    $archiveFetcher = Mockery::mock(WichitaArchivePdfListFetcher::class);
    $archiveFetcher->shouldReceive('fetch')->never();

    $documentersFetcher = Mockery::mock(DocumentersFetcher::class);
    $documentersFetcher->shouldReceive('fetch')
        ->once()
        ->withArgs(function ($scraper): bool {
            return Arr::get($scraper->config, 'list.max_links') === 30;
        })
        ->andReturn([
            [
                'title' => 'Document Story',
                'source' => [
                    'source_url' => 'https://example.com/doc',
                    'source_type' => 'html',
                ],
            ],
        ]);

    $previewer = new ScraperConfigPreviewer(
        rssFetcher: $rssFetcher,
        documentersFetcher: $documentersFetcher,
        genericListingFetcher: $genericFetcher,
        wichitaArchivePdfListFetcher: $archiveFetcher,
    );

    $result = $previewer->preview(
        cityId: 1,
        organizationId: null,
        type: 'html',
        sourceUrl: 'https://example.com/listing',
        config: [
            'profile' => 'wichitadocumenters',
            'list' => [
                'link_selector' => 'a[href]',
                'link_attr' => 'href',
                'max_links' => 30,
            ],
            'article' => [
                'content_selector' => 'article',
                'remove_selectors' => ['script', 'style'],
            ],
        ],
    );

    expect($result['valid'])->toBeTrue()
        ->and($result['items'][0]['title'])->toBe('Document Story');
});
