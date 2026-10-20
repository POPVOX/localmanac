<?php

use App\Livewire\Admin\Sources\Wizard;
use App\Models\City;
use App\Models\EventSource;
use App\Models\User;
use App\Services\Ingestion\Assistant\SourceDiscoveryService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('scraper-assistant.ai.refine_enabled', false);
    $this->travelTo(now()->setDate(2026, 10, 20)->startOfDay());
});

function timelyCalendarMarkup(): string
{
    return '<timely-calendar data-info="'.rawurlencode(json_encode([
        'id' => 54752780,
        'calendar_url' => 'https://events.timely.fun/example',
    ])).'"></timely-calendar>';
}

it('adds Timely calendars from a homepage or provider URL without advanced settings', function (string $input) {
    $url = 'https://timelyapp.time.ly/api/calendars/54752780/export?format=ics&target=copy';
    $feed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:fall-class\r\n"
        ."SUMMARY:AI\\, social media and news\r\nDTSTART;TZID=America/New_York:20261026T103000\r\n"
        ."DTEND;TZID=America/New_York:20261026T120000\r\nLOCATION:Temple\\, Stamford\r\n"
        ."URL:https://events.timely.fun/example/event/fall-class\r\nEND:VEVENT\r\nEND:VCALENDAR";
    Http::fake([
        'https://learning.example/' => Http::response('<title>Learning in Retirement</title>'
            .str_repeat('<script src="https://events.timely.fun/embed.js" data-src="https://events.timely.fun/example"></script>', 2), 200),
        'https://events.timely.fun/example*' => Http::response(timelyCalendarMarkup(), 200),
        $url => Http::response($feed, 200, ['Content-Type' => 'text/calendar']),
    ]);
    $city = City::factory()->create(['timezone' => 'America/New_York']);

    Livewire::actingAs(User::factory()->create())->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourcePurpose', 'event')
        ->set('sourceUrl', $input)
        ->call('analyze')
        ->assertSet('discoveredType', 'ics')
        ->assertSet('discoveredUrl', $url)
        ->assertSet('previewValid', true)
        ->assertSet('previewItems.0.title', 'AI, social media and news')
        ->assertSet('previewItems.0.location', 'Temple, Stamford')
        ->assertSet('previewItems.0.starts_at', '2026-10-26T10:30:00-04:00')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    expect(EventSource::query()->sole()->source_url)->toBe($url);
})->with(['https://learning.example/', 'https://events.timely.fun/example/agenda']);

it('does not follow Timely embeds for News or untrusted provider URLs', function (string $purpose, string $embed) {
    $url = 'https://learning.example/';
    Http::fake([$url => Http::response('<title>Learning News</title><script src="https://events.timely.fun/embed.js" data-src="'.$embed.'"></script>', 200)]);

    $result = app(SourceDiscoveryService::class)->discover($url, $purpose);

    expect($result['source_url'])->toBe($url);
    Http::assertSentCount(1);
})->with([
    ['article', 'https://events.timely.fun/example'],
    ['event', 'https://events.timely.fun.evil.example/example'],
    ['event', 'https://user@events.timely.fun/example'],
]);

it('does not accept a failed or non-calendar Timely export', function (int $status, string $body) {
    Http::fake([
        'https://events.timely.fun/example' => Http::response(timelyCalendarMarkup(), 200),
        'https://timelyapp.time.ly/api/calendars/54752780/export*' => Http::response($body, $status),
    ]);

    $result = app(SourceDiscoveryService::class)->discover('https://events.timely.fun/example', 'event');
    expect($result['type'])->not->toBe('ics');
})->with([[403, 'Forbidden'], [200, '<html>Calendar unavailable</html>']]);
