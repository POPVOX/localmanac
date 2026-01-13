<?php

use App\Services\Ingestion\CalendarDateParser;

it('parses date and time ranges', function () {
    $parser = new CalendarDateParser;

    $result = $parser->parse('January 15, 2026', '7:00 PM - 8:30 PM', 'America/Chicago');

    expect($result)->not->toBeNull()
        ->and($result['all_day'])->toBeFalse()
        ->and($result['starts_at']->format('Y-m-d H:i'))->toBe('2026-01-15 19:00')
        ->and($result['ends_at']->format('Y-m-d H:i'))->toBe('2026-01-15 20:30');
});

it('parses date-only values as all day', function () {
    $parser = new CalendarDateParser;

    $result = $parser->parse('January 15, 2026', null, 'America/Chicago');

    expect($result)->not->toBeNull()
        ->and($result['all_day'])->toBeTrue()
        ->and($result['starts_at']->format('Y-m-d H:i'))->toBe('2026-01-15 00:00');
});

it('parses iso date time values', function () {
    $parser = new CalendarDateParser;

    $result = $parser->parseIso('2026-01-15T10:00:00', 'America/Chicago');

    expect($result)->not->toBeNull()
        ->and($result['all_day'])->toBeFalse()
        ->and($result['starts_at']->format('Y-m-d H:i'))->toBe('2026-01-15 10:00');
});
