<?php

use App\Models\ScraperRun;

it('flags source and configuration failures for an admin update', function (string $error, string $summary) {
    $run = new ScraperRun([
        'status' => 'failed',
        'error_message' => $error,
    ]);

    expect($run->sourceNeedsUpdate())->toBeTrue()
        ->and($run->sourceIssueSummary())->toBe($summary);
})->with([
    'unreachable source' => [
        'Failed to fetch listing page',
        'The source URL could not be reached. Confirm that it still exists or replace it.',
    ],
    'blocked source' => [
        'Listing page is blocked by anti-bot protection.',
        'The source now blocks automated access. Update the renderer, credentials, or source URL.',
    ],
    'obsolete selectors' => [
        'Scraper list link_selector must exist',
        'The saved scraper configuration is incomplete or no longer matches the source.',
    ],
]);

it('does not flag worker and transient job failures as invalid sources', function (string $status, ?string $error) {
    $run = new ScraperRun([
        'status' => $status,
        'error_message' => $error,
    ]);

    expect($run->sourceNeedsUpdate())->toBeFalse()
        ->and($run->sourceIssueSummary())->toBeNull();
})->with([
    ['failed', 'Run timed out before the worker started.'],
    ['failed', 'Database connection failed.'],
    ['success', 'Failed to fetch listing page'],
    ['failed', null],
]);
