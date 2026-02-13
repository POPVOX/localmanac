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
                    <table summary="Archive Details">
                        <tr>
                            <td><img src="common/images/iconword.gif" alt="323 N Ash"></td>
                            <td>
                                <a href="/Archive.aspx?ADID=100"><span>323 N Ash</span></a><br>
                                <span style="font-style: italic">Abatement of the property located at 323 N Ash</span>
                            </td>
                        </tr>
                    </table>
                    <table summary="Archive Details">
                        <tr>
                            <td><img src="common/images/iconpdf.gif" alt="Agenda Packet"></td>
                            <td>
                                <a href="Archive.aspx?ADID=101"><span> Agenda Packet </span></a><br>
                                <span style="font-style: italic">Board of Bids packet for February</span>
                            </td>
                        </tr>
                    </table>
                    <table summary="Archive Details">
                        <tr>
                            <td><img src="common/images/iconpdf.gif" alt="Duplicate"></td>
                            <td><a href="/Archive.aspx?ADID=100"><span>Duplicate Packet</span></a></td>
                        </tr>
                    </table>
                    <table summary="Archive Details">
                        <tr>
                            <td><img src="common/images/iconpdf.gif" alt="X"></td>
                            <td><a href="/Archive.aspx?ADID=102"><span>X</span></a></td>
                        </tr>
                    </table>
                    <a href="/Archive.aspx?AMID=102">Unrelated</a>
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
        ->and($result['items'][0]['title'])->toBe('Abatement of the property located at 323 N Ash')
        ->and($result['items'][0]['summary'])->toBe('Abatement of the property located at 323 N Ash')
        ->and($result['items'][0]['content_type'])->toBe('docx')
        ->and($result['items'][0]['canonical_url'])->toBe('https://www.wichita.gov/Archive.aspx?ADID=100')
        ->and($result['items'][0]['source']['source_uid'])->toBe('100')
        ->and($result['items'][0]['source']['source_type'])->toBe('docx')
        ->and($result['items'][1]['title'])->toBe('Agenda Packet')
        ->and($result['items'][1]['summary'])->toBe('Board of Bids packet for February')
        ->and($result['items'][1]['content_type'])->toBe('pdf')
        ->and($result['items'][1]['canonical_url'])->toBe('https://www.wichita.gov/Archive.aspx?ADID=101')
        ->and($result['meta']['skipped']['skipped_duplicate'])->toBe(1)
        ->and($result['meta']['skipped']['skipped_empty_title'])->toBe(1)
        ->and($result['meta']['skipped']['skipped_unmatched_href'])->toBe(0);
});
