<?php

use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use Illuminate\Support\Carbon;

it('reports duplicates in dry-run mode without writing changes', function () {
    $city = City::factory()->create();
    $source = EventSource::factory()->create(['city_id' => $city->id]);
    $startsAt = Carbon::parse('2026-02-16 19:30:00', 'UTC');

    $first = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'All-City Middle and High School Honor Choir Concert',
        'starts_at' => $startsAt->copy(),
        'location_name' => 'Century II Concert Hall\\,  225 W Douglas Ave\\, Wichita\\, KS 67202',
        'location_address' => null,
        'source_hash' => sha1('legacy-hash'),
    ]);

    $second = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'All-City Middle and High School Honor Choir Concert',
        'starts_at' => $startsAt,
        'location_name' => 'Century II Concert Hall, 225 W Douglas Ave, Wichita, KS 67202',
        'location_address' => null,
        'source_hash' => sha1('new-hash'),
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $first->id,
        'event_source_id' => $source->id,
        'source_url' => null,
        'external_id' => null,
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $second->id,
        'event_source_id' => $source->id,
        'source_url' => null,
        'external_id' => null,
    ]);

    $this->artisan('events:dedupe --dry-run')
        ->assertExitCode(0);

    expect(Event::count())->toBe(2)
        ->and(EventSourceItem::count())->toBe(2);
});

it('merges duplicate events and re-links source items', function () {
    $city = City::factory()->create();
    $source = EventSource::factory()->create(['city_id' => $city->id]);
    $startsAt = Carbon::parse('2026-02-16 19:30:00', 'UTC');

    $first = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'All-City Middle and High School Honor Choir Concert',
        'starts_at' => $startsAt,
        'location_name' => 'Century II Concert Hall\\,  225 W Douglas Ave\\, Wichita\\, KS 67202',
        'location_address' => null,
        'source_hash' => sha1('legacy-hash'),
    ]);

    $second = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'All-City Middle and High School Honor Choir Concert',
        'starts_at' => $startsAt->copy(),
        'location_name' => 'Century II Concert Hall, 225 W Douglas Ave, Wichita, KS 67202',
        'location_address' => null,
        'source_hash' => sha1('new-hash'),
        'event_url' => 'https://example.com/event',
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $first->id,
        'event_source_id' => $source->id,
        'source_url' => null,
        'external_id' => null,
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $second->id,
        'event_source_id' => $source->id,
        'source_url' => null,
        'external_id' => null,
    ]);

    $this->artisan('events:dedupe')
        ->assertExitCode(0);

    $remaining = Event::firstOrFail();

    expect(Event::count())->toBe(1)
        ->and(EventSourceItem::count())->toBe(1)
        ->and($remaining->location_name)->toBe('Century II Concert Hall, 225 W Douglas Ave, Wichita, KS 67202')
        ->and($remaining->event_url)->toBe('https://example.com/event')
        ->and($remaining->source_hash)->toBe(sha1($city->id.'|all-city middle and high school honor choir concert|2026-02-16 19:30:00|century ii concert hall, 225 w douglas ave, wichita, ks 67202'))
        ->and(EventSourceItem::firstOrFail()->event_id)->toBe($remaining->id);
});
