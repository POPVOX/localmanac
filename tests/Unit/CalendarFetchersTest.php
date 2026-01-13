<?php

use App\Models\City;
use App\Models\EventSource;
use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventNormalizer;
use App\Services\Ingestion\Fetchers\HtmlCalendarFetcher;
use App\Services\Ingestion\Fetchers\IcsFetcher;
use App\Services\Ingestion\Fetchers\JsonApiFetcher;
use App\Services\Ingestion\Fetchers\RssEventsFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('parses ics feeds into event dtos', function () {
    $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:abc-123\r\nSUMMARY:Test Event\r\nDTSTART:20260115T190000\r\nDTEND:20260115T200000\r\nLOCATION:City Hall\r\nDESCRIPTION:Planning meeting\r\nURL:https://example.com/event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    Http::fake([
        'https://example.com/calendar.ics' => Http::response($ics, 200),
    ]);

    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
        'config' => ['timezone' => 'America/Chicago'],
    ]);

    $fetcher = new IcsFetcher(new CalendarDateParser, new EventNormalizer);
    $events = $fetcher->fetch($source);

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('Test Event')
        ->and($events[0]->startsAt->format('Y-m-d H:i'))->toBe('2026-01-15 19:00');
});

it('uses description url when ics url points at the feed and location contains html', function () {
    $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:abc-456\r\nSUMMARY:HTML Location Event\r\nDTSTART:20260115T190000\r\nDTEND:20260115T200000\r\nLOCATION:<div>Event details</div> - Wichita KS 67202\r\nDESCRIPTION: https://example.com/event\r\nURL:/calendar.ics\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    Http::fake([
        'https://example.com/calendar.ics' => Http::response($ics, 200),
    ]);

    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
        'config' => ['timezone' => 'America/Chicago'],
    ]);

    $fetcher = new IcsFetcher(new CalendarDateParser, new EventNormalizer);
    $events = $fetcher->fetch($source);

    expect($events)->toHaveCount(1)
        ->and($events[0]->eventUrl)->toBe('https://example.com/event')
        ->and($events[0]->locationName)->toBeNull()
        ->and($events[0]->description)->toBe('<div>Event details</div> - Wichita KS 67202');
});

it('parses rss feeds into event dtos', function () {
    $rss = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0"><channel><item>'
        .'<title>Community Meetup</title>'
        .'<link>https://example.com/event</link>'
        .'<description>Meet your neighbors.</description>'
        .'<pubDate>Mon, 12 Jan 2026 10:00:00 -0600</pubDate>'
        .'</item></channel></rss>';

    Http::fake([
        'https://example.com/events.rss' => Http::response($rss, 200),
    ]);

    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'rss',
        'source_url' => 'https://example.com/events.rss',
        'config' => ['timezone' => 'America/Chicago'],
    ]);

    $fetcher = new RssEventsFetcher(new CalendarDateParser, new EventNormalizer);
    $events = $fetcher->fetch($source);

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('Community Meetup')
        ->and($events[0]->allDay)->toBeTrue();
});

it('parses json api feeds into event dtos', function () {
    $payload = [
        'events' => [
            [
                'id' => 12,
                'title' => 'JSON Event',
                'start_date' => '2026-01-20 18:00:00',
                'end_date' => '2026-01-20 19:00:00',
                'url' => 'https://example.com/json-event',
                'description' => 'Sample description',
                'venue' => [
                    'venue' => 'Main Library',
                    'address' => '223 S Main St',
                ],
            ],
        ],
    ];

    Http::fake([
        'https://example.com/events.json' => Http::response($payload, 200),
    ]);

    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'json',
        'source_url' => 'https://example.com/events.json',
        'config' => [
            'json' => [
                'list_path' => 'events',
                'timezone' => 'America/Chicago',
                'mapping' => [
                    'title' => 'title',
                    'starts_at' => 'start_date',
                    'ends_at' => 'end_date',
                    'event_url' => 'url',
                    'description' => 'description',
                    'location_name' => 'venue.venue',
                    'location_address' => 'venue.address',
                    'external_id' => 'id',
                ],
            ],
        ],
    ]);

    $fetcher = new JsonApiFetcher(new CalendarDateParser, new EventNormalizer);
    $events = $fetcher->fetch($source);

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('JSON Event')
        ->and($events[0]->locationName)->toBe('Main Library');
});

it('parses Visit Wichita simpleview json into event dtos', function () {
    $payload = json_decode(
        file_get_contents(base_path('tests/Fixtures/visit_wichita_simpleview.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $token = 'simpleview-token';

    Http::fake(function (Request $request) use ($payload, $token) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        expect($query)->toHaveKey('json')
            ->and($query['token'])->toBe($token);

        return Http::response($payload, 200);
    });

    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'json_api',
        'source_url' => 'https://www.visitwichita.com/includes/rest_v2/plugins_events_events_by_date/find/',
        'config' => [
            'profile' => 'visit_wichita_simpleview',
            'json' => [
                'root_path' => 'docs.docs',
            ],
            'auth' => [
                'token' => $token,
            ],
        ],
    ]);

    $fetcher = new JsonApiFetcher(new CalendarDateParser, new EventNormalizer);
    $events = $fetcher->fetch($source);

    expect($events)->toHaveCount(2)
        ->and($events[0]->eventUrl)->toBe('https://www.visitwichita.com/event/winter-gala/')
        ->and($events[0]->startsAt->format('Y-m-d H:i'))->toBe('2026-01-12 19:30')
        ->and($events[0]->allDay)->toBeFalse()
        ->and($events[1]->eventUrl)->toBe('https://www.visitwichita.com/event/downtown-art-walk/')
        ->and($events[1]->startsAt->format('Y-m-d H:i'))->toBe('2026-02-10 00:00')
        ->and($events[1]->allDay)->toBeTrue();
});

it('parses Wichita Public Library libnet json into event dtos', function () {
    $payload = json_decode(
        file_get_contents(base_path('tests/Fixtures/wichita_libnet_libcal.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    Carbon::setTestNow(Carbon::parse('2025-12-01 09:00:00', 'America/Chicago'));

    Http::fake(function (Request $request) use ($payload) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        expect($query)->toHaveKey('event_type')
            ->and($query['event_type'])->toBe('0')
            ->and($query)->toHaveKey('req');

        $req = json_decode($query['req'], true, 512, JSON_THROW_ON_ERROR);

        expect($req['date'])->toBe('2025-12-01')
            ->and($req['days'])->toBe(43)
            ->and($req['private'])->toBeFalse()
            ->and($req['locations'])->toBe([])
            ->and($req['ages'])->toBe([])
            ->and($req['types'])->toBe([]);

        return Http::response($payload, 200);
    });

    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'json_api',
        'source_url' => 'https://wichitalibrary.libnet.info/eeventcaldata?event_type=0',
        'config' => [
            'profile' => 'wichita_libnet_libcal',
            'json' => [
                'root_path' => '',
                'days' => 43,
                'req' => [
                    'private' => false,
                    'locations' => [],
                    'ages' => [],
                    'types' => [],
                ],
            ],
        ],
    ]);

    $fetcher = new JsonApiFetcher(new CalendarDateParser, new EventNormalizer);
    $events = $fetcher->fetch($source);

    Carbon::setTestNow();

    $event = $events[0] ?? null;

    expect($events)->not->toBeEmpty()
        ->and($event)->not->toBeNull()
        ->and($event->title)->toBe('Coloring with Jay Walter')
        ->and($event->startsAt->format('Y-m-d H:i:s'))->toBe('2025-12-28 14:30:00')
        ->and($event->startsAt->getTimezone()->getName())->toBe('America/Chicago')
        ->and($event->eventUrl)->toBe('https://wichitalibrary.libnet.info/event/12345')
        ->and($event->locationName)->toBe('Westlink Branch Library')
        ->and($event->sourceHash)->toBe(sha1('libnet:12345:2025-12-28 14:30:00'));
});

it('parses html calendar listings into event dtos', function () {
    $html = '<div class="calendar">'
        .'<ul>'
        .'<li class="event-item">'
        .'<h3><a href="/event-1"><span>HTML Event</span></a></h3>'
        .'<div class="subHeader">'
        .'<div class="date">January 15, 2026, 7:00 PM - 8:00 PM</div>'
        .'<div class="eventLocation"><div class="name">Community Center</div></div>'
        .'</div>'
        .'</li>'
        .'</ul>'
        .'</div>';

    Http::fake([
        'https://example.com/calendar' => Http::response($html, 200),
    ]);

    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_type' => 'html',
        'source_url' => 'https://example.com/calendar',
        'config' => [
            'timezone' => 'America/Chicago',
            'list' => [
                'item_selector' => '.calendar li',
                'title_selector' => 'h3 a span',
                'date_selector' => '.date',
                'link_selector' => 'h3 a',
                'link_attr' => 'href',
                'location_selector' => '.name',
            ],
            'detail' => [
                'enabled' => false,
            ],
        ],
    ]);

    $fetcher = new HtmlCalendarFetcher(new CalendarDateParser, new EventNormalizer);
    $events = $fetcher->fetch($source);

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('HTML Event')
        ->and($events[0]->eventUrl)->toBe('https://example.com/event-1');
});
