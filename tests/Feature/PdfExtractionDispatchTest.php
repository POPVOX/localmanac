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
use Illuminate\Support\Facades\Queue;
use Mockery as M;

afterEach(function (): void {
    M::close();
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
                && $job->queue === 'scraping';
        }
    );
});
