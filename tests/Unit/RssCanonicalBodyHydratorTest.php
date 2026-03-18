<?php

use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Ingestion\RssCanonicalBodyHydrator;

it('returns null for non-html responses', function () {
    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/file.pdf')
        ->andReturn([
            'url' => 'https://example.com/file.pdf',
            'status_code' => 200,
            'content_type' => 'application/pdf',
            'body' => "%PDF-\x00\x01binary-data",
            'renderer' => 'http',
        ]);

    $hydrator = new RssCanonicalBodyHydrator($pageFetcher, app(HtmlTextExtractor::class));

    expect($hydrator->hydrate('https://example.com/file.pdf'))->toBeNull();
});

it('returns null when extraction fails', function () {
    $pageFetcher = Mockery::mock(PageFetcher::class);
    $pageFetcher->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/article')
        ->andReturn([
            'url' => 'https://example.com/article',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => '<html><main>Broken</main></html>',
            'renderer' => 'http',
        ]);

    $extractor = Mockery::mock(HtmlTextExtractor::class);
    $extractor->shouldReceive('extract')
        ->once()
        ->andThrow(new RuntimeException('Malformed HTML payload'));

    $hydrator = new RssCanonicalBodyHydrator($pageFetcher, $extractor);

    expect($hydrator->hydrate('https://example.com/article'))->toBeNull();
});
