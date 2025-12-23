<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\Fetchers\WichitaArchivePdfListFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('extracts matching archive pdf links and normalizes URLs', function () {
    Http::fake([
        'https://www.wichita.gov/Archive.aspx?AMID=102' => Http::response(
            <<<'HTML'
            <html>
                <body>
                    <a href="/Archive.aspx?ADID=100">Council Packet</a>
                    <a href="Archive.aspx?ADID=101"> Agenda Packet </a>
                    <a href="/Archive.aspx?ADID=100">Duplicate Packet</a>
                    <a href="/Archive.aspx?AMID=102">Unrelated</a>
                    <a href="/Archive.aspx?ADID=102">X</a>
                </body>
            </html>
            HTML,
            200
        ),
    ]);

    $city = City::create(['name' => 'Wichita', 'slug' => 'wichita']);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Archive PDFs',
        'slug' => 'archive-pdfs',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://www.wichita.gov/Archive.aspx?AMID=102',
        'config' => [
            'profile' => 'wichita_archive_pdf_list',
            'list' => [
                'href_contains' => 'Archive.aspx?ADID=',
                'max_links' => 2,
            ],
            'pdf' => ['extract' => true],
        ],
    ]);

    $fetcher = new WichitaArchivePdfListFetcher;
    $result = $fetcher->fetch($scraper);

    expect($result['items'])->toHaveCount(2)
        ->and($result['items'][0]['title'])->toBe('Council Packet')
        ->and($result['items'][0]['canonical_url'])->toBe('https://www.wichita.gov/Archive.aspx?ADID=100')
        ->and($result['items'][0]['source']['source_uid'])->toBe('100')
        ->and($result['items'][1]['canonical_url'])->toBe('https://www.wichita.gov/Archive.aspx?ADID=101')
        ->and($result['meta']['skipped']['skipped_duplicate'])->toBe(1)
        ->and($result['meta']['skipped']['skipped_empty_title'])->toBe(1)
        ->and($result['meta']['skipped']['skipped_unmatched_href'])->toBe(1);
});
