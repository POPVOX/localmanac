<?php

use Tests\TestCase;

uses(TestCase::class);

it('keeps heavy production queue supervisors intentionally constrained', function () {
    expect(config('horizon.defaults.supervisor-default.queue'))->toBe(['default'])
        ->and(config('horizon.defaults.supervisor-background.queue'))->toBe(['calendar', 'analysis', 'enrichment', 'embedding', 'scraping', 'ingestion'])
        ->and(config('horizon.defaults.supervisor-background.timeout'))->toBe((int) env('CHAT_CRAWL_JOB_TIMEOUT', 1200))
        ->and(config('horizon.environments.production.supervisor-default.maxProcesses'))->toBe(1)
        ->and(config('horizon.environments.production.supervisor-background.maxProcesses'))->toBe(1);
});
