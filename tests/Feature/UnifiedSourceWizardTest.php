<?php

use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\EventSources\Index as EventSourcesIndex;
use App\Livewire\Admin\Scrapers\Index as ScrapersIndex;
use App\Livewire\Admin\Sources\Index as SourcesIndex;
use App\Livewire\Admin\Sources\Wizard;
use App\Models\City;
use App\Models\EventSource;
use App\Models\Organization;
use App\Models\Scraper;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('discovers previews and creates an article source from one url', function () {
    config()->set('scraper-assistant.ai.refine_enabled', false);

    $user = User::factory()->create();
    $city = City::factory()->create();
    $page = <<<'HTML'
        <!doctype html><html><head>
        <title>Lawrence City News</title>
        <link rel="alternate" type="application/rss+xml" href="/news/feed.xml">
        </head><body><h1>News</h1></body></html>
        HTML;
    $feed = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Lawrence City News</title><item>
        <title>Street project begins</title>
        <link>https://lawrence.example.gov/news/street-project</link>
        <guid>street-project</guid>
        <description>Construction begins next week with signed detours around the project.</description>
        <pubDate>Mon, 31 Aug 2026 10:00:00 -0500</pubDate>
        </item></channel></rss>
        XML;

    Http::fake([
        'https://lawrence.example.gov/news' => Http::response($page, 200, ['Content-Type' => 'text/html']),
        'https://lawrence.example.gov/news/feed.xml' => Http::response($feed, 200, ['Content-Type' => 'application/rss+xml']),
        'https://lawrence.example.gov/news/street-project' => Http::response('<article>'.str_repeat('Project details. ', 80).'</article>', 200),
    ]);

    Livewire::actingAs($user)->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://lawrence.example.gov/news')
        ->call('analyze')
        ->assertSet('step', 3)
        ->assertSet('discoveredKind', 'article')
        ->assertSet('discoveredType', 'rss')
        ->assertSet('discoveredUrl', 'https://lawrence.example.gov/news/feed.xml')
        ->assertSet('previewValid', true)
        ->assertSee('Street project begins')
        ->call('save')
        ->assertRedirect();

    $scraper = Scraper::query()->sole();

    expect($scraper->city_id)->toBe($city->id)
        ->and($scraper->type)->toBe('rss')
        ->and($scraper->source_url)->toBe('https://lawrence.example.gov/news/feed.xml')
        ->and($scraper->health_status)->toBe('healthy');
});

it('links every primary add source action to the unified wizard', function () {
    $user = User::factory()->create(['is_super_admin' => true]);

    foreach ([AdminDashboard::class, SourcesIndex::class, ScrapersIndex::class, EventSourcesIndex::class] as $component) {
        Livewire::actingAs($user)
            ->test($component)
            ->assertSee(route('admin.sources.create'), false);
    }
});

it('discovers previews and creates an event source from the same wizard', function () {
    $user = User::factory()->create();
    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $ics = "BEGIN:VCALENDAR\r\nX-WR-CALNAME:Lawrence Meetings\r\nBEGIN:VEVENT\r\nUID:council-1\r\nSUMMARY:City Council\r\nDTSTART:20260910T180000\r\nLOCATION:City Hall\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    Http::fake([
        'https://lawrence.example.gov/calendar.ics' => Http::response($ics, 200, ['Content-Type' => 'text/calendar']),
    ]);

    Livewire::actingAs($user)->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://lawrence.example.gov/calendar.ics')
        ->call('analyze')
        ->assertSet('step', 3)
        ->assertSet('discoveredKind', 'event')
        ->assertSet('discoveredType', 'ics')
        ->assertSet('previewValid', true)
        ->assertSee('City Council')
        ->call('save')
        ->assertRedirect();

    $source = EventSource::query()->sole();

    expect($source->city_id)->toBe($city->id)
        ->and($source->name)->toBe('Lawrence Meetings')
        ->and($source->source_type)->toBe('ics')
        ->and($source->health_status)->toBe('healthy');
});

it('does not allow an organization from another city to be attached', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $otherCity = City::factory()->create();
    $organization = Organization::create([
        'city_id' => $otherCity->id,
        'name' => 'Other City Hall',
        'slug' => 'other-city-hall',
        'type' => 'government',
    ]);
    $feed = <<<'XML'
        <?xml version="1.0"?><rss version="2.0"><channel><title>City News</title><item>
        <title>Budget hearing</title><link>https://example.gov/news/budget</link>
        <description>Public hearing details and supporting information for residents.</description>
        <pubDate>Mon, 31 Aug 2026 10:00:00 -0500</pubDate>
        </item></channel></rss>
        XML;

    Http::fake([
        'https://example.gov/news.rss' => Http::response($feed, 200, ['Content-Type' => 'application/rss+xml']),
        'https://example.gov/news/budget' => Http::response('<article>'.str_repeat('Budget details. ', 80).'</article>', 200),
    ]);

    Livewire::actingAs($user)->test(Wizard::class)
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://example.gov/news.rss')
        ->call('analyze')
        ->set('organizationId', $organization->id)
        ->call('preview')
        ->call('save')
        ->assertHasErrors(['organizationId']);

    expect(Scraper::count())->toBe(0);
});
