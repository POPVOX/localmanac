<?php

use App\Livewire\Admin\Sources\Wizard;
use App\Models\City;
use App\Models\EventSource;
use App\Models\User;
use App\Services\Ingestion\Assistant\ScraperAssistantSourceFetcher;
use App\Services\Ingestion\Assistant\SourceDiscoveryService;
use App\Services\Ingestion\Fetchers\HtmlCalendarFetcher;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('scraper-assistant.ai.refine_enabled', false);
});

it('adds LibraryCalendar from its public URL without editing advanced settings', function (string $path) {
    $city = City::factory()->create(['timezone' => 'America/New_York']);
    $listing = file_get_contents(base_path('tests/Fixtures/library_calendar_upcoming.html'));
    $month = '<html><title>Month Calendar</title><section class="calendar calendar--month">'
        .'<div class="js-calendar__data"></div></section>'
        .'<a href="/events/upcoming?branches=main" data-drupal-link-system-path="events/upcoming">Upcoming</a></html>';
    $url = 'https://library.example'.$path;
    $listingUrl = str_contains($path, 'upcoming') ? $url : 'https://library.example/events/upcoming?branches=main';

    $responses = [
        $url => Http::response($month, 200, ['Content-Type' => 'text/html']),
    ];
    $responses[$listingUrl] = Http::response($listing, 200, ['Content-Type' => 'text/html']);
    Http::fake($responses);

    $wizard = Livewire::actingAs(User::factory()->create())->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourcePurpose', 'event')
        ->set('sourceUrl', $url)
        ->call('analyze')
        ->assertSet('step', 3)
        ->assertSet('discoveredType', 'html')
        ->assertSet('discoveredUrl', $listingUrl)
        ->assertSet('previewValid', true)
        ->assertSet('previewItems.0.title', 'Family workshop')
        ->assertSet('previewItems.0.starts_at', '2026-10-17T10:30:00-04:00')
        ->assertSet('previewItems.0.ends_at', '2026-10-17T11:00:00-04:00')
        ->assertSet('previewItems.0.location', 'South End Branch')
        ->assertSet('previewItems.1.starts_at', '2026-11-02T18:00:00-05:00');

    expect($wizard->get('previewItems'))->toHaveCount(2);
    $wizard->call('save')->assertHasNoErrors()->assertRedirect();

    $source = EventSource::query()->sole();
    $events = app(HtmlCalendarFetcher::class)->fetch($source);
    expect($source->city_id)->toBe($city->id)
        ->and($source->source_url)->toBe($listingUrl)
        ->and($events)->toHaveCount(2)
        ->and($events[0]->eventUrl)->toBe('https://library.example/event/family-workshop')
        ->and($events[0]->description)->toBe('A family workshop at the library.')
        ->and($events[1]->endsAt->toIso8601String())->toBe('2026-11-02T19:30:00-05:00');
})->with(['/events/upcoming', '/events', '/events/month']);

it('does not send News sources or unrelated pages to a library calendar', function (string $purpose, string $markup) {
    $url = 'https://library.example/news';
    Http::fake([$url => Http::response('<html><title>Library News</title>'.$markup
        .'<a href="/events/upcoming" data-drupal-link-system-path="events/upcoming">Upcoming</a></html>', 200)]);

    $result = app(SourceDiscoveryService::class)->discover($url, $purpose);
    expect($result['source_url'])->toBe($url);
    Http::assertSentCount(1);
})->with([
    'News destination' => ['article', '<div class="js-calendar__data"></div>'],
    'unrelated page with a calendar navigation link' => ['event', '<h1>Library News</h1>'],
]);

it('does not follow off-site calendar view links or replace a calendar with an empty listing', function (string $href) {
    $url = 'https://library.example/events/month';
    Http::fake([
        $url => Http::response('<div class="js-calendar__data"></div>'
            .'<a data-drupal-link-system-path="events/upcoming" href="'.$href.'">Upcoming</a>', 200),
        'https://library.example/events/upcoming' => Http::response('<html><h1>No events scheduled</h1></html>', 200),
    ]);

    $result = app(SourceDiscoveryService::class)->discover($url, 'event');
    expect($result['source_url'])->toBe($url);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example'));
})->with(['https://other.example/events/upcoming', '/events/upcoming']);

it('automatically previews a calendar containing just one event', function (string $itemMarkup) {
    $city = City::factory()->create(['timezone' => 'America/New_York']);
    Http::fake(['https://library.example/events' => Http::response('<html><title>Library Calendar</title>'.$itemMarkup.'</html>', 200)]);

    Livewire::actingAs(User::factory()->create())->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourcePurpose', 'event')
        ->set('sourceUrl', 'https://library.example/events')
        ->call('analyze')
        ->assertSet('previewValid', true)
        ->assertSet('previewItems.0.title', 'Workshop')
        ->assertSet('previewItems.0.starts_at', '2026-10-17T10:30:00-04:00');
})->with([
    'generic calendar' => '<div class="calendar-event"><h3><a href="/workshop">Workshop</a></h3><time datetime="2026-10-17T10:30:00-04:00"></time></div>',
    'LibraryCalendar' => '<article class="node--type-lc-event moderation-state--published"><h3 class="lc-event__title"><a href="/workshop">Workshop</a></h3><div class="lc-date-icon">Oct 17 2026 Sat</div><div class="lc-event-info-item--time">10:30am–11:00am</div></article>',
]);

it('keeps saving disabled when every library event is cancelled', function () {
    $city = City::factory()->create();
    $listing = str_replace('moderation-state--published', 'moderation-state--cancelled',
        file_get_contents(base_path('tests/Fixtures/library_calendar_upcoming.html')));
    Http::fake(['https://library.example/events/upcoming' => Http::response($listing, 200)]);

    Livewire::actingAs(User::factory()->create())->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourcePurpose', 'event')
        ->set('sourceUrl', 'https://library.example/events/upcoming')
        ->call('analyze')
        ->assertSet('previewValid', false)
        ->assertSet('previewItems', [])
        ->call('save')
        ->assertHasErrors('sourceUrl');
    expect(EventSource::count())->toBe(0);
});

it('explains blocked calendar access without routing testers to Chat or advanced settings', function () {
    Http::fake(['https://blocked.example/calendar' => Http::response('Forbidden', 403)]);
    $this->mock(ScraperAssistantSourceFetcher::class, function (MockInterface $mock): void {
        $mock->shouldReceive('fetch')->once()->with('https://blocked.example/calendar')
            ->andThrow(new InvalidArgumentException('Unable to fetch source page content.'));
    });
    $city = City::factory()->create();

    $wizard = Livewire::actingAs(User::factory()->create())->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourcePurpose', 'event')
        ->set('sourceUrl', 'https://blocked.example/calendar')
        ->call('analyze')
        ->assertSet('step', 1)
        ->assertSet('previewValid', false);
    expect($wizard->get('discoveryError'))->toContain('public iCalendar/ICS feed')
        ->not->toContain('Chat source');
    expect(EventSource::count())->toBe(0);
});
