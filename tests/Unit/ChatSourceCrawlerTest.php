<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\ChatSourceGuard;
use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\Ingestion\ChatSourceCrawler;
use App\Services\Chat\Ingestion\NavigationPageClassifier;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Chat\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        ->with('https://www.wichita.gov/m/faq', Mockery::any(), [], true)
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
        app(NavigationPageClassifier::class),
    );

    $pages = $crawler->crawl($source);

    expect($pages)->toHaveCount(1)
        ->and($pages[0]['url'])->toBe('https://www.wichita.gov/m/faq');
});

it('follows municipal notices and PDFs after filtering menu links and honors the source limit', function () {
    config(['chat.link_limit' => 1, 'chat.crawl_max_depth' => 1, 'chat.crawl_min_text_chars' => 1]);
    $source = ChatSource::factory()->create([
        'source_url' => 'https://www.example.gov/public-notices.html',
        'crawl_renderer' => 'auto', 'link_limit' => 2,
    ]);
    $menu = str_repeat('<a href="/shopping">Shopping</a>', 30);
    Http::preventStrayRequests();
    Http::fake([
        $source->source_url => Http::response('<html><body><nav>'.$menu.'</nav><div class="mainContent">'
            .'<h2>Public Notice Portal</h2><a href="mailto:clerk@example.gov">Email</a>'
            .'<a href="https://sponsor.example/sale">Sponsor</a>'
            .'<a href="/cdn-cgi/blocked">Blocked</a><a href="#section">Jump</a>'
            .'<a href="/council/agenda">City Council Agenda</a><a href="/council/agenda#top">Duplicate</a>'
            .'<a href="https://example.gov/notices/hearing.pdf">Hearing notice</a>'
            .'<a href="/extra">Beyond the source limit</a></div></body></html>', 200),
        'https://www.example.gov/council/agenda' => Http::response('<main><p>Council meets Tuesday at city hall.</p></main>', 200),
        'https://example.gov/notices/hearing.pdf' => Http::response('%PDF-1.7 fixture', 200, ['Content-Type' => 'application/pdf']),
    ]);
    $this->mock(PdfTextExtractor::class)->shouldReceive('extract')->once()->with('%PDF-1.7 fixture')
        ->andReturn('The public hearing begins Tuesday at noon.');

    $pages = app(ChatSourceCrawler::class)->crawl($source);

    expect(array_column($pages, 'url'))->toBe([
        $source->source_url, 'https://www.example.gov/council/agenda', 'https://example.gov/notices/hearing.pdf',
    ])->and($pages[2]['content_type'])->toBe('pdf');
    Http::assertSentCount(3);
});

it('does not follow links when disabled on the source', function (string $mode, int $limit) {
    $source = ChatSource::factory()->create([
        'source_url' => 'https://example.gov/start', 'crawl_renderer' => 'http',
        'link_follow_mode' => $mode, 'link_limit' => $limit,
    ]);
    Http::preventStrayRequests();
    Http::fake([$source->source_url => Http::response('<main><p>Read city services here.</p><a href="/detail">More details</a></main>', 200)]);

    expect(app(ChatSourceCrawler::class)->crawl($source))->toHaveCount(1);
    Http::assertSentCount(1);
})->with([['none', 6], ['auto', 0]]);

it('traverses a navigation directory without indexing it as answer content', function () {
    $source = ChatSource::factory()->create(['source_url' => 'https://example.gov/start', 'crawl_renderer' => 'http']);
    Http::preventStrayRequests();
    Http::fake([
        $source->source_url => Http::response('<main><a href="/detail">https://example.gov/detail</a></main>', 200),
        'https://example.gov/detail' => Http::response('<main><p>Submit your building permit application to the permit center.</p></main>', 200),
    ]);

    $pages = app(ChatSourceCrawler::class)->crawl($source);

    expect(array_column($pages, 'url'))->toBe(['https://example.gov/detail']);
    Http::assertSentCount(2);
});

it('bounds fetches even when every visited page is a navigation directory', function () {
    config(['chat.crawl_max_pages' => 2, 'chat.crawl_max_depth' => 10]);
    $source = ChatSource::factory()->create(['source_url' => 'https://example.gov/start', 'crawl_renderer' => 'http']);
    Http::preventStrayRequests();
    Http::fake([
        $source->source_url => Http::response('<main><a href="/next">https://example.gov/next</a></main>', 200),
        'https://example.gov/next' => Http::response('<main><a href="/third">https://example.gov/third</a></main>', 200),
    ]);

    expect(app(ChatSourceCrawler::class)->crawl($source))->toBeEmpty();
    Http::assertSentCount(2);
});
