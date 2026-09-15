<?php

use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\ArticleWriter;
use App\Services\Ingestion\Deduplicator;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\Fetchers\WichitaArchivePdfListFetcher;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery as M;

afterEach(function (): void {
    M::close();
});

it('queues extraction for municipal documents discovered by the generic listing fetcher', function () {
    Queue::fake();
    Http::preventStrayRequests();
    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Public notices',
        'slug' => 'public-notices',
        'type' => 'html',
        'source_url' => 'https://example.gov/public-notices.html',
        'is_enabled' => true,
        'config' => [
            'profile' => 'generic_listing',
            'fetch' => ['renderer' => 'http'],
            'list' => ['link_selector' => '.mainContent a[href]', 'max_links' => 10],
            'article' => ['content_selector' => 'main'],
        ],
    ]);
    Http::fake([
        $scraper->source_url => Http::response('<div class="mainContent">'
            .'<a href="/notices/hearing.pdf?download=1">Public hearing notice</a></div>', 200),
    ]);

    $run = app(ScrapeRunner::class)->run($scraper);
    $article = Article::where('scraper_id', $scraper->id)->sole();

    expect($run->status)->toBe('success')
        ->and($run->items_created)->toBe(1)
        ->and($article->content_type)->toBe('pdf')
        ->and($article->body)->toBeNull()
        ->and($article->sources()->sole()->source_type)->toBe('pdf');
    Queue::assertPushed(ExtractPdfBody::class, fn (ExtractPdfBody $job): bool => $job->articleId === $article->id
        && $job->pdfUrl === 'https://example.gov/notices/hearing.pdf?download=1'
        && $job->queue === 'scraping');
    Http::assertSentCount(1);
});

it('queues pdf extraction jobs for pdf items', function () {
    Queue::fake();

    $city = City::create(['name' => 'Wichita', 'slug' => 'wichita']);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Archive PDFs',
        'slug' => 'archive-pdfs',
        'type' => 'html',
        'source_url' => 'https://www.wichita.gov/Archive.aspx?AMID=102',
        'is_enabled' => true,
        'config' => [
            'profile' => 'wichita_archive_pdf_list',
            'list' => [
                'href_contains' => 'Archive.aspx?ADID=',
                'max_links' => 25,
            ],
            'pdf' => ['extract' => true],
        ],
    ]);

    $fetcher = M::mock(WichitaArchivePdfListFetcher::class);
    app()->instance(WichitaArchivePdfListFetcher::class, $fetcher);

    $fetcher->shouldReceive('fetch')
        ->once()
        ->andReturn([
            'items' => [
                [
                    'city_id' => $city->id,
                    'scraper_id' => $scraper->id,
                    'title' => 'Budget PDF',
                    'content_type' => 'pdf',
                    'canonical_url' => 'https://www.wichita.gov/Archive.aspx?ADID=9999',
                    'source' => [
                        'source_url' => 'https://www.wichita.gov/Archive.aspx?ADID=9999',
                        'source_type' => 'pdf',
                    ],
                ],
            ],
            'meta' => [],
        ]);

    $runner = new ScrapeRunner(new Deduplicator, new ArticleWriter, new RssFetcher);

    $runner->run($scraper);

    $article = Article::first();

    expect($article)->not->toBeNull()
        ->and($article?->content_type)->toBe('pdf');

    Queue::assertPushed(
        ExtractPdfBody::class,
        function (ExtractPdfBody $job) use ($article): bool {
            return $job->articleId === $article?->id
                && $job->pdfUrl === 'https://www.wichita.gov/Archive.aspx?ADID=9999'
                && $job->connection === 'redis'
                && $job->queue === 'scraping';
        }
    );
});
