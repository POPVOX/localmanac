<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\ChatSourceGuard;
use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\Ingestion\ChatSourceCrawler;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Chat\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('skips cloudflare infrastructure links during crawl', function () {
    $city = City::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.wichita.gov/m/faq',
    ]);

    $fetcher = Mockery::mock(PageFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->once()
        ->with('https://www.wichita.gov/m/faq', Mockery::any())
        ->andReturn([
            'url' => 'https://www.wichita.gov/m/faq',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => <<<'HTML'
                <html>
                    <head><title>FAQ</title></head>
                    <body>
                        <main>
                            <p>Find answers to city service questions.</p>
                            <a href="/cdn-cgi/l/email-protection">Protected email</a>
                        </main>
                    </body>
                </html>
                HTML,
            'renderer' => 'http',
        ]);

    $crawler = new ChatSourceCrawler(
        $fetcher,
        app(HtmlTextExtractor::class),
        app(PdfTextExtractor::class),
        app(ChatSourceGuard::class),
    );

    $pages = $crawler->crawl($source);

    expect($pages)->toHaveCount(1)
        ->and($pages[0]['url'])->toBe('https://www.wichita.gov/m/faq');
});
