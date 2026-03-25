<?php

use App\Services\Chat\Event\EventWindowResolver;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('resolves this weekend to friday through sunday in the city timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:30:00', 'America/Chicago'));

    $window = (new EventWindowResolver)->resolve("what's going on this weekend?", 'America/Chicago');

    expect($window)->not->toBeNull()
        ->and($window['label'])->toBe('this weekend')
        ->and($window['start_at']->format('Y-m-d H:i:s'))->toBe('2026-02-13 00:00:00')
        ->and($window['end_at']->format('Y-m-d H:i:s'))->toBe('2026-02-15 23:59:59');
});

it('resolves tomorrow as a single-day window', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:30:00', 'America/Chicago'));

    $window = (new EventWindowResolver)->resolve('What events are tomorrow?', 'America/Chicago');

    expect($window)->not->toBeNull()
        ->and($window['label'])->toBe('tomorrow')
        ->and($window['start_at']->format('Y-m-d H:i:s'))->toBe('2026-02-13 00:00:00')
        ->and($window['end_at']->format('Y-m-d H:i:s'))->toBe('2026-02-13 23:59:59');
});

it('resolves next month in the city timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:30:00', 'America/Chicago'));

    $window = (new EventWindowResolver)->resolve('What events are next month?', 'America/Chicago');

    expect($window)->not->toBeNull()
        ->and($window['label'])->toBe('next month')
        ->and($window['start_at']->format('Y-m-d H:i:s'))->toBe('2026-03-01 00:00:00')
        ->and($window['end_at']->format('Y-m-d H:i:s'))->toBe('2026-03-31 23:59:59');
});

it('resolves month-day without year to the current year', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:30:00', 'America/Chicago'));

    $window = (new EventWindowResolver)->resolve('March 12th events', 'America/Chicago');

    expect($window)->not->toBeNull()
        ->and($window['label'])->toBe('March 12, 2026')
        ->and($window['start_at']->format('Y-m-d'))->toBe('2026-03-12');
});

it('resolves explicit date ranges', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:30:00', 'America/Chicago'));

    $window = (new EventWindowResolver)->resolve('events from 2026-03-12 to 2026-03-14', 'America/Chicago');

    expect($window)->not->toBeNull()
        ->and($window['label'])->toBe('March 12, 2026 to March 14, 2026')
        ->and($window['start_at']->format('Y-m-d'))->toBe('2026-03-12')
        ->and($window['end_at']->format('Y-m-d'))->toBe('2026-03-14');
});

it('resolves next 14 days as an explicit 14-day window', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:30:00', 'America/Chicago'));

    $window = (new EventWindowResolver)->resolve('What meetings are coming up in Wichita in the next 14 days?', 'America/Chicago');

    expect($window)->not->toBeNull()
        ->and($window['label'])->toBe('next 14 days')
        ->and($window['is_explicit'])->toBeTrue()
        ->and($window['parse_confidence'])->toBe(1.0)
        ->and($window['start_at']->format('Y-m-d H:i:s'))->toBe('2026-02-12 00:00:00')
        ->and($window['end_at']->format('Y-m-d H:i:s'))->toBe('2026-02-25 23:59:59');
});

it('returns null for unsupported temporal phrasing', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:30:00', 'America/Chicago'));

    $window = (new EventWindowResolver)->resolve('Can you summarize city initiatives?', 'America/Chicago');

    expect($window)->toBeNull();
});
