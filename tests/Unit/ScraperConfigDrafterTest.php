<?php

use App\Services\Ingestion\Assistant\ScraperConfigDrafter;

uses(Tests\TestCase::class);

it('detects the wichita archive pdf list profile from archive html', function () {
    config()->set('scraper-assistant.ai.refine_enabled', false);

    $html = <<<'HTML'
    <html>
        <body>
            <table summary="Archive Details">
                <tr>
                    <td><a href="/Archive.aspx?ADID=100">Agenda Packet</a></td>
                </tr>
            </table>
        </body>
    </html>
    HTML;

    $draft = app(ScraperConfigDrafter::class)->draft(
        'html',
        'https://www.wichita.gov/Archive.aspx?AMID=102',
        $html,
    );

    expect($draft['profile'])->toBe('wichita_archive_pdf_list')
        ->and($draft['config']['profile'])->toBe('wichita_archive_pdf_list')
        ->and($draft['config']['list']['href_contains'])->toBe('Archive.aspx?ADID=');
});

it('detects the documenters profile from google docs links', function () {
    config()->set('scraper-assistant.ai.refine_enabled', false);

    $html = <<<'HTML'
    <html>
        <body>
            <main>
                <a href="https://docs.google.com/document/d/abc123/edit">Meeting Notes</a>
            </main>
        </body>
    </html>
    HTML;

    $draft = app(ScraperConfigDrafter::class)->draft(
        'html',
        'https://example.com/meetings',
        $html,
    );

    expect($draft['profile'])->toBe('wichitadocumenters')
        ->and($draft['config']['profile'])->toBe('wichitadocumenters')
        ->and($draft['config']['list']['link_selector'])->toBe('a[href*="docs.google.com"]');
});

it('falls back to generic listing profile when no specific pattern is detected', function () {
    config()->set('scraper-assistant.ai.refine_enabled', false);

    $html = file_get_contents(base_path('tests/Fixtures/generic_listing_page.html'));

    expect($html)->toBeString();

    $draft = app(ScraperConfigDrafter::class)->draft(
        'html',
        'https://example.com/listing',
        (string) $html,
    );

    expect($draft['profile'])->toBe('generic_listing')
        ->and($draft['config']['profile'])->toBe('generic_listing')
        ->and($draft['config']['list']['link_selector'])->toBeString()
        ->and($draft['config']['article']['content_selector'])->toBeString();
});
