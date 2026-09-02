<?php

use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('prefers the event url for public links', function () {
    $event = Event::factory()->create([
        'event_url' => 'https://events.example.com/meeting',
    ]);

    expect($event->publicUrl())->toBe('https://events.example.com/meeting');
});

it('falls back to the most recently fetched source item url', function () {
    $city = City::factory()->create();
    $event = Event::factory()->create([
        'city_id' => $city->id,
        'event_url' => null,
    ]);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $event->id,
        'event_source_id' => $source->id,
        'source_url' => 'https://events.example.com/older',
        'fetched_at' => now()->subHour(),
    ]);
    EventSourceItem::factory()->create([
        'event_id' => $event->id,
        'event_source_id' => $source->id,
        'source_url' => 'https://events.example.com/newer',
        'fetched_at' => now(),
    ]);

    expect($event->publicUrl())->toBe('https://events.example.com/newer');
});

it('falls back to the event source home when no item url exists', function () {
    $city = City::factory()->create();
    $event = Event::factory()->create([
        'city_id' => $city->id,
        'event_url' => null,
    ]);
    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://events.example.com/calendar',
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $event->id,
        'event_source_id' => $source->id,
        'source_url' => null,
    ]);

    expect($event->publicUrl())->toBe('https://events.example.com/calendar');
});
