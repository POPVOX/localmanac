<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('scrape:schedule')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('calendar:schedule')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('chat:schedule')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('sources:check-health --limit=20')
    ->cron('17 */6 * * *')
    ->withoutOverlapping()
    ->runInBackground();
