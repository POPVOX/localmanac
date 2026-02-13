<?php

use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\EventNormalizer;
use App\Services\Ingestion\EventWriter;
use App\Services\Ingestion\PostgresSequenceSynchronizer;
use Illuminate\Database\UniqueConstraintViolationException;
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

it('decodes html entities in titles before saving', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $writer = new EventWriter(new EventNormalizer);

    $event = new EventDTO(
        title: 'Art House 310 First Friday &#8211; Amy Herrman',
        startsAt: Carbon::parse('2026-02-06 18:00', 'UTC'),
        endsAt: null,
        allDay: false,
        locationName: 'Art House 310',
        locationAddress: null,
        description: null,
        eventUrl: null,
        externalId: 'arthouse-1',
        sourceUrl: null,
        rawPayload: [],
    );

    $writer->write($source, $event);

    expect(Event::firstOrFail()->title)->toBe('Art House 310 First Friday – Amy Herrman');
});

it('uses canonical hash when an equivalent normalized event already exists', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $writer = new EventWriter(new EventNormalizer);
    $startsAt = Carbon::parse('2026-01-15 10:00', 'UTC');

    $firstEvent = new EventDTO(
        title: 'Board Meeting',
        startsAt: $startsAt,
        endsAt: null,
        allDay: false,
        locationName: 'City Hall, 123 Main St',
        locationAddress: null,
        description: null,
        eventUrl: null,
        externalId: 'uid-123',
        sourceUrl: 'https://example.com/events/board-meeting',
        rawPayload: [],
    );

    $writer->write($source, $firstEvent);

    $sameEventWithDifferentSourceHash = new EventDTO(
        title: 'Board Meeting',
        startsAt: $startsAt,
        endsAt: null,
        allDay: false,
        locationName: 'City Hall\\, 123 Main St',
        locationAddress: null,
        description: null,
        eventUrl: null,
        externalId: 'uid-123',
        sourceUrl: 'https://example.com/events/board-meeting',
        rawPayload: [],
        sourceHash: 'vendor-specific-hash-v2'
    );

    $writer->write($source, $sameEventWithDifferentSourceHash);

    expect(Event::count())->toBe(1)
        ->and(EventSourceItem::count())->toBe(1);
});

it('does not split events when only location address formatting changes', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $writer = new EventWriter(new EventNormalizer);
    $startsAt = Carbon::parse('2026-02-25 10:00', 'UTC');

    $firstEvent = new EventDTO(
        title: 'Senior Wednesday',
        startsAt: $startsAt,
        endsAt: null,
        allDay: false,
        locationName: 'Wichita-Sedgwick Co. Historical Museum',
        locationAddress: null,
        description: null,
        eventUrl: null,
        externalId: 'senior-1',
        sourceUrl: null,
        rawPayload: [],
    );

    $secondEvent = new EventDTO(
        title: 'Senior Wednesday',
        startsAt: $startsAt,
        endsAt: null,
        allDay: false,
        locationName: 'Wichita-Sedgwick Co. Historical Museum',
        locationAddress: '204 S Main St\\, Wichita\\, KS',
        description: null,
        eventUrl: null,
        externalId: 'senior-1',
        sourceUrl: null,
        rawPayload: [],
        sourceHash: 'upstream-v2-senior-wednesday'
    );

    $writer->write($source, $firstEvent);
    $writer->write($source, $secondEvent);

    $saved = Event::firstOrFail();

    expect(Event::count())->toBe(1)
        ->and($saved->location_name)->toBe('Wichita-Sedgwick Co. Historical Museum')
        ->and($saved->location_address)->toBe('204 S Main St, Wichita, KS');
});

it('retries event writes once after recoverable sequence drift is repaired', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $dto = new EventDTO(
        title: 'Recovered Event',
        startsAt: Carbon::parse('2026-01-15 10:00', 'America/Chicago'),
        endsAt: null,
        allDay: false,
        locationName: 'City Hall',
        locationAddress: '123 Main St',
        description: null,
        eventUrl: 'https://example.com/recovered-event',
        externalId: 'recovered-1',
        sourceUrl: 'https://example.com/recovered-event',
        rawPayload: [],
    );

    $stored = new Event([
        'city_id' => 1,
        'title' => 'Recovered Event',
        'source_hash' => sha1('recovered-event'),
    ]);
    $stored->id = 123;

    $recoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "events"',
        [],
        new \RuntimeException('duplicate key value violates unique constraint "events_pkey"')
    );

    $synchronizer = \Mockery::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(['events', 'event_source_items'])
        ->andReturn(true);

    $writer = \Mockery::mock(EventWriter::class, [new EventNormalizer, $synchronizer])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $writer->shouldReceive('persistEvent')
        ->once()
        ->andThrow($recoverableViolation);
    $writer->shouldReceive('persistEvent')
        ->once()
        ->andReturn($stored);

    $result = $writer->write($source, $dto);

    expect($result)->toBe($stored)
        ->and($result->id)->toBe(123);
});

it('does not retry when unique constraint errors are not recoverable sequence drift', function () {
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.com/calendar.ics',
    ]);

    $dto = new EventDTO(
        title: 'Non Recoverable',
        startsAt: Carbon::parse('2026-01-15 10:00', 'America/Chicago'),
        endsAt: null,
        allDay: false,
        locationName: null,
        locationAddress: null,
        description: null,
        eventUrl: null,
        externalId: null,
        sourceUrl: null,
        rawPayload: [],
    );

    $nonRecoverableViolation = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "events"',
        [],
        new \RuntimeException('duplicate key value violates unique constraint "events_source_hash_unique"')
    );

    $synchronizer = \Mockery::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')->never();

    $writer = \Mockery::mock(EventWriter::class, [new EventNormalizer, $synchronizer])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $writer->shouldReceive('persistEvent')
        ->once()
        ->andThrow($nonRecoverableViolation);

    expect(fn () => $writer->write($source, $dto))
        ->toThrow(UniqueConstraintViolationException::class);
});
