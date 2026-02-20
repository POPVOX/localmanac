<?php

use App\Services\Ingestion\Assistant\ScraperConfigPreviewer;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

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
