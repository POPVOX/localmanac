<?php

use App\Services\Ingestion\Assistant\SourceDiscoveryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('scraper-assistant.ai.refine_enabled', false);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('discovers an article feed from a public news page', function () {
    Http::fake([
        'https://lawrence.example.gov/news' => Http::response(<<<'HTML'
            <!doctype html>
            <html><head>
                <title>Lawrence City News</title>
                <link rel="alternate" type="application/rss+xml" href="/news/feed.xml">
            </head><body><main><h1>Latest news</h1></main></body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://lawrence.example.gov/news');

    expect($result['kind'])->toBe('article')
        ->and($result['type'])->toBe('rss')
        ->and($result['source_url'])->toBe('https://lawrence.example.gov/news/feed.xml')
        ->and($result['config']['feed_url'])->toBe('https://lawrence.example.gov/news/feed.xml')
        ->and($result['endpoints'])->toHaveCount(1);
});

it('classifies an event page and drafts reusable html calendar selectors', function () {
    Http::fake([
        'https://jackson.example.gov/calendar' => Http::response(<<<'HTML'
            <!doctype html>
            <html><head><title>Jackson Community Calendar</title>
            <script type="application/ld+json">{"@type":"Event","name":"Planning meeting"}</script>
            </head><body><main>
                <article class="event"><h2><a href="/events/1">Planning meeting</a></h2><time datetime="2026-09-10T18:00:00-05:00">September 10</time></article>
                <article class="event"><h2><a href="/events/2">Library board</a></h2><time datetime="2026-09-12T10:00:00-05:00">September 12</time></article>
            </main></body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://jackson.example.gov/calendar');

    expect($result['kind'])->toBe('event')
        ->and($result['type'])->toBe('html')
        ->and($result['config']['profile'])->toBe('generic_html_list')
        ->and($result['config']['list']['item_selector'])->toBe('article.event')
        ->and($result['config']['list']['datetime_selector'])->toBe('time[datetime]');
});

it('recognizes direct ics and json event endpoints', function () {
    $ics = "BEGIN:VCALENDAR\r\nX-WR-CALNAME:City Meetings\r\nBEGIN:VEVENT\r\nSUMMARY:Council\r\nDTSTART:20260910T180000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    Http::fake([
        'https://example.gov/calendar.ics' => Http::response($ics, 200, ['Content-Type' => 'text/calendar']),
        'https://example.gov/api/events.json' => Http::response([
            'events' => [[
                'name' => 'Council meeting',
                'startDate' => '2026-09-10T18:00:00-05:00',
            ]],
        ], 200),
    ]);

    $icsResult = app(SourceDiscoveryService::class)->discover('https://example.gov/calendar.ics');
    $jsonResult = app(SourceDiscoveryService::class)->discover('https://example.gov/api/events.json');

    expect($icsResult['kind'])->toBe('event')
        ->and($icsResult['type'])->toBe('ics')
        ->and($icsResult['name'])->toBe('City Meetings')
        ->and($jsonResult['kind'])->toBe('event')
        ->and($jsonResult['type'])->toBe('json_api')
        ->and(data_get($jsonResult, 'config.json.root_path'))->toBe('events')
        ->and(data_get($jsonResult, 'config.json.mapping.starts_at'))->toBe('startDate');
});

it('inspects a discovered json endpoint before drafting its event mapping', function () {
    Http::fake([
        'https://example.gov/calendar' => Http::response(<<<'HTML'
            <!doctype html><html><head><title>Community Calendar</title></head><body>
            <a href="/api/calendar/events.json" type="application/json">Calendar data</a>
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
        'https://example.gov/api/calendar/events.json' => Http::response([
            'results' => [[
                'summary' => 'Council meeting',
                'start' => ['dateTime' => '2026-09-10T18:00:00-05:00'],
                'venue' => ['name' => 'City Hall', 'address' => '100 Main St'],
            ]],
        ], 200),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://example.gov/calendar');

    expect($result['kind'])->toBe('event')
        ->and($result['type'])->toBe('json_api')
        ->and($result['source_url'])->toBe('https://example.gov/api/calendar/events.json')
        ->and(data_get($result, 'config.json.root_path'))->toBe('results')
        ->and(data_get($result, 'config.json.mapping.title'))->toBe('summary')
        ->and(data_get($result, 'config.json.mapping.starts_at'))->toBe('start.dateTime')
        ->and(data_get($result, 'config.json.mapping.location_name'))->toBe('venue.name')
        ->and(data_get($result, 'config.json.mapping.location_address'))->toBe('venue.address');
});

it('follows a CivicPlus calendar subscription page to its aggregate event feed', function () {
    $calendarPage = <<<'HTML'
        <!doctype html><html><head><title>Calendar • Madison County, TN • CivicEngage</title></head><body>
        <main id="CalendarContent">
            <h1>Calendar</h1>
            <a href="/rss.aspx#calendar" aria-label="View RSS Feeds for Calendar">View RSS Feeds</a>
            <a href="/iCalendar.aspx">Subscribe to iCalendar</a>
        </main>
        <footer>Government Websites by CivicPlus</footer>
        </body></html>
        HTML;
    $rssChooser = <<<'HTML'
        <!doctype html><html><body>
        <div class="listing listingIcon calendar" name="calendar">
            <h2>Calendar</h2>
            <a href="/RSSFeed.aspx?ModID=58&amp;CID=All-calendar.xml">All</a>
            <a href="/RSSFeed.aspx?ModID=58&amp;CID=County-Commission-24">County Commission</a>
        </div>
        </body></html>
        HTML;
    $feed = <<<'XML'
        <?xml version="1.0"?><rss version="2.0" xmlns:calendarEvent="https://madison.example.gov/Calendar.aspx"><channel>
        <title>Madison County, TN - Calendar</title><description>Get the latest events</description>
        <item><title>Long Range Planning Committee</title>
        <link>https://madison.example.gov/Calendar.aspx?EID=3917</link>
        <pubDate>Wed, 26 Aug 2026 15:19:37 -0600</pubDate>
        <description>Event date: September 1, 2026 Event Time: 02:00 PM - 03:30 PM</description>
        <calendarEvent:EventDates>September 1, 2026</calendarEvent:EventDates>
        <calendarEvent:EventTimes>02:00 PM - 03:30 PM</calendarEvent:EventTimes>
        </item></channel></rss>
        XML;

    Http::fake([
        'https://madison.example.gov/calendar.aspx*' => Http::response($calendarPage, 200, ['Content-Type' => 'text/html']),
        'https://madison.example.gov/rss.aspx*' => Http::response($rssChooser, 200, ['Content-Type' => 'text/html']),
        'https://madison.example.gov/RSSFeed.aspx*' => Http::response($feed, 200, ['Content-Type' => 'text/xml']),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://madison.example.gov/calendar.aspx?');

    expect($result['kind'])->toBe('event')
        ->and($result['type'])->toBe('rss')
        ->and($result['source_url'])->toBe('https://madison.example.gov/RSSFeed.aspx?ModID=58&CID=All-calendar.xml')
        ->and($result['name'])->toBe('Madison County, TN - Calendar')
        ->and($result['endpoints'][0]['label'])->toBe('Event feed');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://madison.example.gov/rss.aspx');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://madison.example.gov/RSSFeed.aspx?ModID=58&CID=All-calendar.xml');
});

it('discovers the CivicWeb meetings api instead of scraping its empty calendar shell', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'America/Chicago'));

    $portal = <<<'HTML'
        <!doctype html><html><head><title>City of Lawrence - Meeting Schedule</title></head><body>
        <a href="/Portal/MeetingSchedule.aspx">Calendar</a>
        <div class="upcoming-meeting-list">Loading...</div>
        <script>Portal.MeetingCalendarPage.createInstance({});</script>
        </body></html>
        HTML;
    $meetings = [[
        'ExternalCalendar' => false,
        'Id' => 6109,
        'MeetingDate' => '2026-09-01',
        'MeetingDateTime' => '2026-09-01 17:45',
        'MeetingLocation' => 'City Commission Room',
        'Name' => 'City Commission - Sep 01 2026',
    ]];

    Http::fake([
        'https://lawrenceks.civicweb.net/portal/' => Http::response($portal, 200, ['Content-Type' => 'text/html']),
        'https://lawrenceks.civicweb.net/Services/MeetingsService.svc/meetings*' => Http::response($meetings, 200, ['Content-Type' => 'application/json']),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://lawrenceks.civicweb.net/portal/');

    expect($result['kind'])->toBe('event')
        ->and($result['type'])->toBe('json_api')
        ->and($result['source_url'])->toBe('https://lawrenceks.civicweb.net/Services/MeetingsService.svc/meetings')
        ->and(data_get($result, 'config.json.root_path'))->toBe('')
        ->and(data_get($result, 'config.json.url_template'))->toBe('https://lawrenceks.civicweb.net/Services/MeetingsService.svc/meetings?month={month}&year={year}&surroundingmonths=0')
        ->and(data_get($result, 'config.json.event_url_template'))->toBe('https://lawrenceks.civicweb.net/Portal/MeetingInformation.aspx?Org=Cal&Id={Id}')
        ->and(data_get($result, 'config.json.months_forward'))->toBe(12)
        ->and(data_get($result, 'config.json.mapping.title'))->toBe('Name')
        ->and(data_get($result, 'config.json.mapping.starts_at'))->toBe('MeetingDateTime')
        ->and(data_get($result, 'config.json.mapping.location_name'))->toBe('MeetingLocation')
        ->and(data_get($result, 'config.json.mapping.external_id'))->toBe('Id');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://lawrenceks.civicweb.net/Services/MeetingsService.svc/meetings?month=8&year=2026&surroundingmonths=0');
});
