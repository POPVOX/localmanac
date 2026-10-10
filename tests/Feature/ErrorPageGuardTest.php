<?php

use App\Services\Chat\ChatSourceGuard;
use App\Services\Chat\Ingestion\HttpPageFetcher;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Chat\Ingestion\PlaywrightPageFetcher;

it('rejects soft error pages even when the transport reports HTTP 200', function () {
    $http = Mockery::mock(HttpPageFetcher::class);
    $http->shouldReceive('fetch')->andReturn([
        'url' => 'https://example.gov/missing', 'status_code' => 200, 'content_type' => 'text/html',
        'renderer' => 'http', 'body' => '<html><head><title>404 Not Found</title></head><body>404 Not Found</body></html>',
    ]);
    app()->instance(HttpPageFetcher::class, $http);
    app()->instance(PlaywrightPageFetcher::class, Mockery::mock(PlaywrightPageFetcher::class));
    expect(app(PageFetcher::class)->fetch('https://example.gov/missing', 'http'))->toBeNull();
});

it('does not reject an article merely discussing server errors', function () {
    expect(app(ChatSourceGuard::class)->isBlockedPage('https://example.gov/news', null,
        'How the city fixed its 404 errors', 'The website once returned 404 Not Found for residents. The city has repaired it.'))->toBeFalse();
});
