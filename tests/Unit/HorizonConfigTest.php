<?php

use Tests\TestCase;

uses(TestCase::class);

it('keeps heavy production queue supervisors intentionally constrained', function () {
    expect(config('horizon.defaults.supervisor-default.queue'))->toBe(['default'])
        ->and(config('horizon.defaults.supervisor-background.queue'))->toBe(['analysis', 'enrichment', 'embedding', 'ingestion'])
        ->and(config('horizon.defaults.supervisor-scraping.queue'))->toBe(['calendar', 'scraping'])
        ->and(config('horizon.defaults.supervisor-scraping.timeout'))->toBe(180)
        ->and(config('horizon.defaults.supervisor-background.timeout'))->toBe((int) env('CHAT_CRAWL_JOB_TIMEOUT', 1200))
        ->and(config('horizon.environments.production.supervisor-default.maxProcesses'))->toBe(1)
        ->and(config('horizon.environments.production.supervisor-background.maxProcesses'))->toBe(1)
        ->and(config('horizon.environments.production.supervisor-scraping.maxProcesses'))->toBe(1);
});
