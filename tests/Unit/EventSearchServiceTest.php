<?php

use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use App\Services\Chat\Event\EventSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('includes overlapping events in the window and orders results by start time', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $window = [
        'start_at' => Carbon::parse('2026-02-13 00:00:00', 'America/Chicago'),
        'end_at' => Carbon::parse('2026-02-15 23:59:59', 'America/Chicago'),
        'label' => 'this weekend',
        'is_explicit' => false,
        'parse_confidence' => 1.0,
    ];

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Thursday meeting',
        'starts_at' => Carbon::parse('2026-02-12 18:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-12 22:00:00', 'America/Chicago'),
        'source_hash' => sha1('event-out-1'),
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Friday kickoff',
        'starts_at' => Carbon::parse('2026-02-12 12:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-13 12:00:00', 'America/Chicago'),
        'source_hash' => sha1('event-in-1'),
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Saturday market',
        'starts_at' => Carbon::parse('2026-02-14 10:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-14 15:00:00', 'America/Chicago'),
        'source_hash' => sha1('event-in-2'),
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Monday concert',
        'starts_at' => Carbon::parse('2026-02-16 19:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-16 21:00:00', 'America/Chicago'),
        'source_hash' => sha1('event-out-2'),
    ]);

    expect(Event::query()->where('city_id', $city->id)->count())->toBe(4);

    $result = app(EventSearchService::class)->search(
        city: $city,
        window: $window,
        question: 'what is going on this weekend?',
        limit: 8,
    );

    expect($result['total'])->toBe(2)
        ->and($result['has_more'])->toBeFalse()
        ->and(collect($result['events'])->pluck('title')->all())
        ->toBe(['Friday kickoff', 'Saturday market']);
});

it('enforces default max results and has_more when more events are available', function () {
    config()->set('chat.events.max_results', 8);

    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $window = [
        'start_at' => Carbon::parse('2026-02-13 00:00:00', 'America/Chicago'),
        'end_at' => Carbon::parse('2026-02-15 23:59:59', 'America/Chicago'),
        'label' => 'this weekend',
        'is_explicit' => false,
        'parse_confidence' => 1.0,
    ];

    foreach (range(1, 10) as $index) {
        Event::factory()->create([
            'city_id' => $city->id,
            'title' => "Weekend Event {$index}",
            'starts_at' => Carbon::parse('2026-02-13 08:00:00', 'America/Chicago')->addHours($index),
            'ends_at' => Carbon::parse('2026-02-13 09:00:00', 'America/Chicago')->addHours($index),
            'source_hash' => sha1("weekend-event-{$index}"),
        ]);
    }

    expect(Event::query()->where('city_id', $city->id)->count())->toBe(10);

    $result = app(EventSearchService::class)->search(
        city: $city,
        window: $window,
        question: 'events this weekend',
        limit: null,
    );

    expect($result['total'])->toBe(10)
        ->and($result['has_more'])->toBeTrue()
        ->and($result['events'])->toHaveCount(8);
});

it('applies optional keyword filtering', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $window = [
        'start_at' => Carbon::parse('2026-02-13 00:00:00', 'America/Chicago'),
        'end_at' => Carbon::parse('2026-02-15 23:59:59', 'America/Chicago'),
        'label' => 'this weekend',
        'is_explicit' => false,
        'parse_confidence' => 1.0,
    ];

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Downtown rock concert',
        'starts_at' => Carbon::parse('2026-02-14 19:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-14 22:00:00', 'America/Chicago'),
        'source_hash' => sha1('concert'),
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Farmers market',
        'starts_at' => Carbon::parse('2026-02-14 09:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-14 12:00:00', 'America/Chicago'),
        'source_hash' => sha1('market'),
    ]);

    $result = app(EventSearchService::class)->search(
        city: $city,
        window: $window,
        question: 'concerts this weekend',
        limit: 8,
    );

    expect($result['total'])->toBe(1)
        ->and($result['events'])->toHaveCount(1)
        ->and($result['events'][0]['title'])->toBe('Downtown rock concert');
});

it('uses citation url fallback chain from event to source item to source home', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $window = [
        'start_at' => Carbon::parse('2026-02-13 00:00:00', 'America/Chicago'),
        'end_at' => Carbon::parse('2026-02-15 23:59:59', 'America/Chicago'),
        'label' => 'this weekend',
        'is_explicit' => false,
        'parse_confidence' => 1.0,
    ];

    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita Events Calendar',
        'source_url' => 'https://events.wichita.gov',
        'is_active' => true,
    ]);

    $eventWithDirectUrl = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Event with direct URL',
        'starts_at' => Carbon::parse('2026-02-14 09:00:00', 'America/Chicago'),
        'event_url' => 'https://example.com/event-direct',
        'source_hash' => sha1('event-direct'),
    ]);

    $eventWithSourceItemUrl = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Event with item URL',
        'starts_at' => Carbon::parse('2026-02-14 12:00:00', 'America/Chicago'),
        'event_url' => null,
        'source_hash' => sha1('event-item-url'),
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $eventWithSourceItemUrl->id,
        'event_source_id' => $source->id,
        'source_url' => 'https://example.com/event-item',
        'fetched_at' => Carbon::parse('2026-02-13 12:00:00', 'America/Chicago'),
    ]);

    $eventWithSourceHome = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Event with source home',
        'starts_at' => Carbon::parse('2026-02-14 15:00:00', 'America/Chicago'),
        'event_url' => null,
        'source_hash' => sha1('event-source-home'),
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $eventWithSourceHome->id,
        'event_source_id' => $source->id,
        'source_url' => null,
        'fetched_at' => Carbon::parse('2026-02-13 13:00:00', 'America/Chicago'),
    ]);

    $result = app(EventSearchService::class)->search(
        city: $city,
        window: $window,
        question: 'events this weekend',
        limit: 8,
    );

    $byTitle = collect($result['events'])->keyBy('title');

    expect($byTitle['Event with direct URL']['source_url'])->toBe('https://example.com/event-direct')
        ->and($byTitle['Event with item URL']['source_url'])->toBe('https://example.com/event-item')
        ->and($byTitle['Event with source home']['source_url'])->toBe('https://events.wichita.gov')
        ->and($byTitle['Event with source home']['source_name'])->toBe('Wichita Events Calendar');
});
