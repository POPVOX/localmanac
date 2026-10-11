<?php

use App\Models\EventSource;
use App\Services\Ingestion\Fetchers\HtmlCalendarFetcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function fetchCalendarHtml(string $html, array $config): array
{
    Http::preventStrayRequests();
    Http::fake(['https://example.org/events' => Http::response($html)]);
    $source = new EventSource(['source_type' => 'html', 'source_url' => 'https://example.org/events', 'config' => $config + ['timezone' => 'America/Chicago']]);

    return app(HtmlCalendarFetcher::class)->fetch($source);
}

it('keeps valid JSON-LD end times and discards nonpositive durations', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        ['@type' => 'Event', 'name' => 'Workshop', 'startDate' => '2026-10-11T10:00:00-05:00', 'endDate' => '2026-10-11'],
        ['@type' => 'Event', 'name' => 'All day', 'startDate' => '2026-10-11', 'endDate' => '2026-10-11'],
        ['@type' => 'Event', 'name' => 'Concert', 'startDate' => '2026-10-11T19:00:00-05:00', 'endDate' => '2026-10-11T21:00:00-05:00'],
    ]).'</script>';
    $events = fetchCalendarHtml($html, ['profile' => 'json_ld_events']);

    expect($events)->toHaveCount(3)
        ->and($events[0]->endsAt)->toBeNull()
        ->and($events[0]->allDay)->toBeFalse()
        ->and($events[1]->endsAt)->toBeNull()
        ->and($events[1]->allDay)->toBeTrue()
        ->and($events[2]->endsAt->format('H:i'))->toBe('21:00');
});

it('reads explicit all-day markers and separate start and end attributes', function () {
    $html = '<article><h2>District closed</h2><time datetime="2026-10-12T00:00:00-05:00"></time><span class="all-day">all day</span></article>'
        .'<article><h2>County meeting</h2><time datetime="2026-10-14T16:00:00-05:00"></time><time datetime="2026-10-14T17:00:00-05:00"></time><p>Open to the public</p></article>';
    $events = fetchCalendarHtml($html, ['profile' => 'generic_html_list', 'list' => [
        'item_selector' => 'article', 'title_selector' => 'h2', 'datetime_selector' => 'time',
        'end_datetime_selector' => 'time + time', 'all_day_selector' => '.all-day', 'description_selector' => 'p',
    ]]);

    expect($events)->toHaveCount(2)
        ->and($events[0]->allDay)->toBeTrue()
        ->and($events[0]->endsAt)->toBeNull()
        ->and($events[1]->allDay)->toBeFalse()
        ->and($events[1]->endsAt->format('H:i'))->toBe('17:00')
        ->and($events[1]->description)->toBe('Open to the public');
});

it('reads inline FullCalendar literals without importing empty duplicate or old records', function () {
    Carbon::setTestNow('2026-10-11');
    try {
        $events = fetchCalendarHtml(<<<'HTML'
<script id="calendar">$("#calendar").fullCalendar({events:[
{id:1,title:"Concert: \"Winter\" [a,b]",url:"/concert",start:"2026-12-15T19:30",end:"2026-12-15T21:30",},
{id:1,title:"Concert: \"Winter\" [a,b]",url:"/concert",start:"2026-12-15T19:30",end:"2026-12-15T21:30"},
{id:1,title:"Concert",url:"/concert",start:"",end:""},
{id:2,title:"Historical",start:"2018-01-01"},
{id:3,title:"School\'s closure",start:"2026-10-12",allDay:true},
{id:4,title:"Later performance",url:"/concert",start:"2026-12-16T19:30",end:"2026-12-16"},
]});</script>
HTML, ['profile' => 'fullcalendar_inline', 'location_name' => 'Concert Hall']);

        expect($events)->toHaveCount(3)
            ->and($events[0]->title)->toBe('Concert: "Winter" [a,b]')
            ->and($events[0]->eventUrl)->toBe('https://example.org/concert')
            ->and($events[0]->endsAt->toIso8601String())->toBe('2026-12-15T21:30:00-06:00')
            ->and($events[0]->locationName)->toBe('Concert Hall')
            ->and($events[1]->allDay)->toBeTrue()
            ->and($events[1]->title)->toBe("School's closure")
            ->and($events[2]->endsAt)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects executable FullCalendar values instead of evaluating JavaScript', function () {
    $events = fetchCalendarHtml('<script>calendar({events:[{title:"Injected",start:fetch("/private")} ]})</script>', ['profile' => 'fullcalendar_inline']);
    expect($events)->toBeEmpty();
    Http::assertSentCount(1);
});

it('discovers Finalsite school calendars with titles and all-day labels', function () {
    $html = '<div class="fsCalendar">'.str_repeat('<article><div class="fsTitle"><a href="/event">District closed</a></div><time datetime="2026-10-12T00:00:00-05:00"></time><div class="fsAllDay">all day</div></article>', 2).'</div>';
    $draft = app(\App\Services\Ingestion\Assistant\EventSourceConfigDrafter::class)->draft('html', 'https://example.org/events', $html);
    $events = fetchCalendarHtml($html, $draft['config']);

    expect($events)->toHaveCount(2)
        ->and($events[0]->title)->toBe('District closed')
        ->and($events[0]->allDay)->toBeTrue();
});

it('discovers literal FullCalendar data as a calendar profile', function () {
    $draft = app(\App\Services\Ingestion\Assistant\EventSourceConfigDrafter::class)->draft('html', 'https://example.org/events', '<script>$("#calendar").fullCalendar({events:[{title:"Concert",start:"2026-10-12"}]});</script>');
    expect($draft['config']['profile'])->toBe('fullcalendar_inline');
});
