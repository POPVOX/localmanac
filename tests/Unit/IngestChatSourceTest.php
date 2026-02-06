<?php

use App\Jobs\IngestChatSource;
use Tests\TestCase;

uses(TestCase::class);

it('uses the configured crawl job timeout', function () {
    config()->set('chat.crawl_job_timeout', 1800);

    $job = new IngestChatSource(123);

    expect($job->timeout)->toBe(1800);
});
