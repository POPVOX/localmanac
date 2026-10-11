<?php

use App\Models\EventSource;
use App\Services\Ingestion\Assistant\EventSourceConfigDrafter;
use App\Services\Ingestion\Fetchers\HtmlCalendarFetcher;
use Illuminate\Support\Facades\Http;

function sidearmScheduleTable(): array
{
    // Captured Nuxt/Sidearm reference-table shape, reduced to one game.
    return [
        ['ShallowReactive', 1], ['pinia' => 2], ['Reactive', 3], ['schedule' => 4],
        ['schedules' => 5], ['schedules-football,' => 6],
        ['school_name' => 7, 'sport' => 8, 'games' => 10, 'hidden' => 11],
        'Kansas', ['title' => 9], 'Football', [12, 12], false,
        ['id' => 13, 'date' => 14, 'time' => 15, 'tbd' => 11, 'opponent' => 16,
            'location_indicator' => 18, 'location' => 19, 'facility' => 20,
            'game_center_enabled' => 21, 'game_center_link' => 22, 'noplay_text' => 23, 'enddate' => 24],
        1001, '2026-10-17T11:00:00', '11 a.m. CT', ['title' => 17], 'Kansas State',
        'A', 'Manhattan, Kan.', null, true, '/game-center/1001', '', '2026-10-17T00:00:00',
    ];
}

function fetchSidearmSchedule(array $table, array $config = []): array
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://example.org/schedule' => Http::response('<script id="__NUXT_DATA__" type="application/json">'.json_encode($table).'</script>')]);

    return app(HtmlCalendarFetcher::class)->fetch(new EventSource([
        'city_id' => 4,
        'source_type' => 'html',
        'source_url' => 'https://example.org/schedule',
        'config' => $config + ['profile' => 'sidearm_schedule', 'timezone' => 'America/Chicago'],
    ]));
}

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 11)));

it('maps structured athletics schedules with exact dates and stable identities', function () {
    $table = sidearmScheduleTable();
    $events = fetchSidearmSchedule($table);

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('Kansas Football at Kansas State')
        ->and($events[0]->startsAt->toIso8601String())->toBe('2026-10-17T11:00:00-05:00')
        ->and($events[0]->endsAt)->toBeNull()
        ->and($events[0]->allDay)->toBeFalse()
        ->and($events[0]->locationName)->toBe('Manhattan, Kan.')
        ->and($events[0]->eventUrl)->toBe('https://example.org/game-center/1001')
        ->and($events[0]->externalId)->toBe('1001');

    $table[14] = '2026-10-17T14:00:00';
    $updated = fetchSidearmSchedule($table);
    expect($updated[0]->sourceHash)->toBe($events[0]->sourceHash)
        ->and($updated[0]->startsAt->format('H:i'))->toBe('14:00');
});

it('skips games without a usable announced time or active date', function (string $field, mixed $value) {
    $table = sidearmScheduleTable();
    $table[12][$field] = count($table);
    $table[] = $value;

    expect(fetchSidearmSchedule($table))->toBeEmpty();
})->with([
    ['time', 'TBA'], ['time', ''], ['time', '11 a.m. or 2 p.m. CT'], ['tbd', true],
    ['date', '2020-10-17T11:00:00'], ['date', 'invalid'], ['noplay_text', 'Postponed'],
    ['noplay_text', 'Canceled'],
]);

it('can restrict a city calendar to home games and use the schedule when game center is unavailable', function () {
    $table = sidearmScheduleTable();
    expect(fetchSidearmSchedule($table, ['home_only' => true]))->toBeEmpty();
    $table[18] = 'H';
    $table[21] = false;
    $events = fetchSidearmSchedule($table, ['home_only' => true]);
    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('Kansas Football vs Kansas State')
        ->and($events[0]->eventUrl)->toBe('https://example.org/schedule');
});

it('fails closed for cyclic or unknown Nuxt wrappers', function (array $root) {
    $table = sidearmScheduleTable();
    $table[0] = $root;
    expect(fetchSidearmSchedule($table))->toBeEmpty();
})->with([[['Reactive', 0]], [['Executable', 1]], [['Reactive', 99999]]]);

it('discovers structured schedules without classifying generic Nuxt pages as calendars', function () {
    $html = '<script id="__NUXT_DATA__" type="application/json">'.json_encode(sidearmScheduleTable()).'</script>';
    $draft = app(EventSourceConfigDrafter::class)->draft('html', 'https://example.org/schedule', $html);
    expect($draft['config']['profile'])->toBe('sidearm_schedule');
    $other = '<script id="__NUXT_DATA__" type="application/json">[{"state":1},{}]</script>';
    expect(\App\Services\Ingestion\Fetchers\HtmlProfiles\SidearmScheduleProfile::hasScheduleData($other))->toBeFalse();
});
