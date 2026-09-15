<?php

use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\Ingestion\HttpPageFetcher;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Chat\Ingestion\PlaywrightPageFetcher;
use Tests\TestCase;

uses(TestCase::class);

it('skips playwright when extracted text is sufficient', function () {
    config()->set('chat.crawl_renderer', 'auto');
    config()->set('chat.crawl_min_text_chars', 50);
    config()->set('chat.playwright_min_html_chars', 20000);

    $html = '<html><body><main>'.str_repeat('word ', 40).'</main></body></html>';

    $httpFetcher = Mockery::mock(HttpPageFetcher::class);
    $httpFetcher->shouldReceive('fetch')
        ->once()
        ->andReturn([
            'url' => 'https://example.com',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $html,
            'renderer' => 'http',
        ]);

    $playwrightFetcher = Mockery::mock(PlaywrightPageFetcher::class);
    $playwrightFetcher->shouldReceive('fetch')->never();

    $fetcher = new PageFetcher($httpFetcher, $playwrightFetcher, app(HtmlTextExtractor::class));

    $result = $fetcher->fetch('https://example.com');

    expect($result['renderer'])->toBe('http');
});

it('uses playwright when content is too short and looks like a js shell', function () {
    config()->set('chat.crawl_renderer', 'auto');
    config()->set('chat.crawl_min_text_chars', 200);
    config()->set('chat.playwright_min_html_chars', 20000);

    $html = '<html><body><div id="__next"></div></body></html>';

    $httpFetcher = Mockery::mock(HttpPageFetcher::class);
    $httpFetcher->shouldReceive('fetch')
        ->once()
        ->andReturn([
            'url' => 'https://example.com',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $html,
            'renderer' => 'http',
        ]);

    $playwrightFetcher = Mockery::mock(PlaywrightPageFetcher::class);
    $playwrightFetcher->shouldReceive('fetch')
        ->once()
        ->andReturn([
            'url' => 'https://example.com',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => '<html><body><main>Rendered</main></body></html>',
            'renderer' => 'playwright',
        ]);

    $fetcher = new PageFetcher($httpFetcher, $playwrightFetcher, app(HtmlTextExtractor::class));

    $result = $fetcher->fetch('https://example.com');

    expect($result['renderer'])->toBe('playwright');
});

it('respects http override when provided', function () {
    config()->set('chat.crawl_renderer', 'auto');
    config()->set('chat.crawl_min_text_chars', 200);
    config()->set('chat.playwright_min_html_chars', 20000);

    $html = '<html><body><div id="__next"></div></body></html>';

    $httpFetcher = Mockery::mock(HttpPageFetcher::class);
    $httpFetcher->shouldReceive('fetch')
        ->once()
        ->andReturn([
            'url' => 'https://example.com',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $html,
            'renderer' => 'http',
        ]);

    $playwrightFetcher = Mockery::mock(PlaywrightPageFetcher::class);
    $playwrightFetcher->shouldReceive('fetch')->never();

    $fetcher = new PageFetcher($httpFetcher, $playwrightFetcher, app(HtmlTextExtractor::class));

    $result = $fetcher->fetch('https://example.com', 'http');

    expect($result['renderer'])->toBe('http');
});

it('respects playwright override when provided', function () {
    config()->set('chat.crawl_renderer', 'auto');

    $httpFetcher = Mockery::mock(HttpPageFetcher::class);
    $httpFetcher->shouldReceive('fetch')->never();

    $playwrightFetcher = Mockery::mock(PlaywrightPageFetcher::class);
    $playwrightFetcher->shouldReceive('fetch')
        ->once()
        ->andReturn([
            'url' => 'https://example.com',
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => '<html><body><main>Rendered</main></body></html>',
            'renderer' => 'playwright',
        ]);

    $fetcher = new PageFetcher($httpFetcher, $playwrightFetcher, app(HtmlTextExtractor::class));

    $result = $fetcher->fetch('https://example.com', 'playwright');

    expect($result['renderer'])->toBe('playwright');
});

it('rejects non-html binary responses before extraction', function () {
    config()->set('chat.crawl_renderer', 'auto');

    $httpFetcher = Mockery::mock(HttpPageFetcher::class);
    $httpFetcher->shouldReceive('fetch')
        ->once()
        ->andReturn([
            'url' => 'https://example.com/agenda.pdf',
            'status_code' => 200,
            'content_type' => 'application/pdf',
            'body' => "%PDF-\x00\x01binary-data",
            'renderer' => 'http',
        ]);

    $playwrightFetcher = Mockery::mock(PlaywrightPageFetcher::class);
    $playwrightFetcher->shouldReceive('fetch')->never();

    $fetcher = new PageFetcher($httpFetcher, $playwrightFetcher, app(HtmlTextExtractor::class));

    expect($fetcher->fetch('https://example.com/agenda.pdf'))->toBeNull();
});

it('passes PDF bytes to callers that have a document extractor without launching a browser', function (string $contentType) {
    config(['chat.crawl_renderer' => 'auto']);
    $body = "%PDF-1.7\n\xFF\xFE binary-data";
    $httpFetcher = Mockery::mock(HttpPageFetcher::class);
    $httpFetcher->shouldReceive('fetch')->once()->andReturn([
        'url' => 'https://example.gov/download?id=123',
        'status_code' => 200, 'content_type' => $contentType,
        'body' => $body, 'renderer' => 'http',
    ]);
    $playwrightFetcher = Mockery::mock(PlaywrightPageFetcher::class);
    $playwrightFetcher->shouldReceive('fetch')->never();
    $htmlExtractor = Mockery::mock(HtmlTextExtractor::class);
    $htmlExtractor->shouldReceive('extract')->never();
    $fetcher = new PageFetcher($httpFetcher, $playwrightFetcher, $htmlExtractor);

    $result = $fetcher->fetch('https://example.gov/download?id=123', allowPdf: true);

    expect($result['body'])->toBe($body)
        ->and($result['content_type'])->toBe('application/pdf')
        ->and($result['renderer'])->toBe('http');
})->with(['application/pdf', 'application/octet-stream']);
