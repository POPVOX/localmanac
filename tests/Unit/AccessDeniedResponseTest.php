<?php

use App\Services\Chat\Ingestion\AccessDeniedResponse;
use App\Services\Chat\Ingestion\HttpPageFetcher;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('retains an entity-encoded Akamai support reference without copying the error body', function () {
    $body = '<h1>Access Denied</h1>Private request data Reference&#32;&#35;18&#46;beaa3717&#46;1792575947&#46;45c8946';

    expect(AccessDeniedResponse::describe(403, $body, 'AkamaiGHost'))
        ->toContain('Akamai security service', 'HTTP 403', 'Reference: 18.beaa3717.1792575947.45c8946.', 'website operator')
        ->not->toContain('Private request data', '<h1>');
});

it('recognizes a rendered Akamai error without response headers', function () {
    $body = 'Access Denied Reference #18.bfaa3717.1792575948.14d5c860 https://errors.edgesuite.net/18.bfaa3717.1792575948.14d5c860';

    expect(AccessDeniedResponse::describe(403, $body))
        ->toContain('Reference: 18.bfaa3717.1792575948.14d5c860.');
});

it('does not attribute unrelated responses to Akamai', function (int $status, string $body, ?string $server) {
    expect(AccessDeniedResponse::describe($status, $body, $server))->toBeNull();
})->with([
    [200, 'https://errors.edgesuite.net/', 'AkamaiGHost'],
    [503, 'Maintenance', 'AkamaiGHost'],
    [403, 'Sign in to access this page. Reference #18.123.456.789', 'nginx'],
    [403, 'Access denied', null],
]);

it('omits malformed or unbounded references', function (string $reference) {
    expect(AccessDeniedResponse::describe(403, 'Reference #'.$reference, 'AkamaiGHost'))
        ->toContain('Akamai security service')
        ->not->toContain('Reference:');
})->with(['secret-token', '18.abc.123.'.str_repeat('a', 33), '18.abc.123.abc.evil']);

it('propagates a denied HTTP response through retry exception handling', function () {
    config(['chat.fetch_retries' => 1]);
    Http::fake(['example.gov/*' => Http::response(
        'Access Denied Reference #18.beaa3717.1792575947.45c8946',
        403,
        ['Server' => 'AkamaiGHost', 'Content-Type' => 'text/html'],
    )]);
    $fetcher = app(HttpPageFetcher::class);

    expect($fetcher->fetch('https://example.gov/services'))->toBeNull()
        ->and($fetcher->lastFailure)->toContain('Akamai', '18.beaa3717.1792575947.45c8946');
});
