<?php

use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\Ingestion\HttpPageFetcher;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Chat\Ingestion\PlaywrightPageFetcher;
use App\Services\Ingestion\DownloadPolicy;
use App\Services\Ingestion\DownloadRejected;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('rejects a video response before reading its body and does not fall back to a browser', function () {
    Http::globalOptions(['handler' => new MockHandler([
        new Response(200, ['Content-Type' => 'video/mp4', 'Content-Length' => '130774179']),
    ])]);
    config(['chat.fetch_retries' => 1]);
    $browser = Mockery::mock(PlaywrightPageFetcher::class);
    $browser->shouldNotReceive('fetch');
    $fetcher = new PageFetcher(new HttpPageFetcher, $browser, app(HtmlTextExtractor::class));

    expect($fetcher->fetch('https://example.gov/download?id=1'))->toBeNull();
});

it('rejects oversized documents at the headers without a browser retry', function () {
    Http::globalOptions(['handler' => new MockHandler([
        new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => '30000000']),
    ])]);
    config(['chat.fetch_retries' => 1]);
    $browser = Mockery::mock(PlaywrightPageFetcher::class);
    $browser->shouldNotReceive('fetch');
    $fetcher = new PageFetcher(new HttpPageFetcher, $browser, app(HtmlTextExtractor::class));

    expect($fetcher->fetch('https://example.gov/document.pdf', allowPdf: true))->toBeNull();
});

it('still retrieves small PDFs', function () {
    Http::globalOptions(['handler' => new MockHandler([
        new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.7 test document'),
    ])]);
    $browser = Mockery::mock(PlaywrightPageFetcher::class);
    $browser->shouldNotReceive('fetch');
    $fetcher = new PageFetcher(new HttpPageFetcher, $browser, app(HtmlTextExtractor::class));

    expect($fetcher->fetch('https://example.gov/document.pdf', allowPdf: true)['body'])
        ->toBe('%PDF-1.7 test document');
});

it('caps transfers even when Content-Length is unknown', function () {
    config(['ingestion.max_download_bytes' => 1024]);
    $progress = (new DownloadPolicy)->options()['progress'];
    $progress(0, 1024);

    expect(fn () => $progress(0, 1025))->toThrow(DownloadRejected::class);
});

it('skips media URLs in every renderer mode', function (string $renderer) {
    $http = Mockery::mock(HttpPageFetcher::class);
    $http->shouldNotReceive('fetch');
    $browser = Mockery::mock(PlaywrightPageFetcher::class);
    $browser->shouldNotReceive('fetch');
    $fetcher = new PageFetcher($http, $browser, app(HtmlTextExtractor::class));

    expect($fetcher->fetch('https://example.gov/meeting.MP4?download=1', $renderer))->toBeNull();
})->with(['auto', 'http', 'playwright']);
