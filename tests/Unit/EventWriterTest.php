<?php

use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\EventNormalizer;
use App\Services\Ingestion\EventWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('upserts events and source items by source hash', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $writer = new EventWriter(new EventNormalizer);
    $startsAt = Carbon::parse('2026-01-15 10:00', 'America/Chicago');
    $endsAt = Carbon::parse('2026-01-15 11:00', 'America/Chicago');

    $event = new EventDTO(
        title: 'Board Meeting',
        startsAt: $startsAt,
        endsAt: $endsAt,
        allDay: false,
        locationName: 'City Hall',
        locationAddress: '123 Main St',
        description: 'Agenda overview',
        eventUrl: 'https://example.com/events/board-meeting',
        externalId: 'uid-123',
        sourceUrl: 'https://example.com/events/board-meeting',
        rawPayload: [
            'uid' => 'uid-123',
        ],
    );

    $writer->write($source, $event);
    $writer->write($source, $event);

    expect(Event::count())->toBe(1)
        ->and(EventSourceItem::count())->toBe(1)
        ->and(Event::first()->source_hash)->toHaveLength(40);
});

it('sanitizes location fields before saving', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $writer = new EventWriter(new EventNormalizer);
    $startsAt = Carbon::parse('2026-01-15 10:00', 'America/Chicago');

    $event = new EventDTO(
        title: 'Screening',
        startsAt: $startsAt,
        endsAt: null,
        allDay: false,
        locationName: 'Westlink Church of Christ\\, 10025 W. Central\\, Wichita\\, KS 67212',
        locationAddress: '10025 W. Central\\, Wichita\\, KS 67212',
        description: null,
        eventUrl: null,
        externalId: null,
        sourceUrl: null,
        rawPayload: [],
    );

    $writer->write($source, $event);

    $saved = Event::firstOrFail();

    expect($saved->location_name)->toBe('Westlink Church of Christ, 10025 W. Central, Wichita, KS 67212')
        ->and($saved->location_address)->toBe('10025 W. Central, Wichita, KS 67212');
});
