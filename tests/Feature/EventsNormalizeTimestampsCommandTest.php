<?php

use App\Models\City;
use App\Models\Event;
use Illuminate\Support\Carbon;

function makeEventNormalizationCity(): City
{
    return City::create([
        'name' => 'Wichita',
        'slug' => 'wichita-events',
        'timezone' => 'America/Chicago',
    ]);
}

it('audits legacy event timestamps without writing changes', function () {
    $city = makeEventNormalizationCity();

    $event = Event::create([
        'city_id' => $city->id,
        'title' => 'Legacy Board Meeting',
        'starts_at' => '2026-03-13 18:00:00',
        'ends_at' => '2026-03-13 19:00:00',
        'all_day' => false,
        'source_hash' => sha1('legacy-board-meeting'),
    ]);

    $this->artisan('events:normalize-timestamps')
        ->assertSuccessful()
        ->expectsOutputToContain('Audit mode only. Pass --apply to persist changes.')
        ->expectsOutputToContain('needs_update: 1')
        ->expectsOutputToContain('updated: 0');

    expect($event->fresh()?->starts_at?->toAtomString())->toBe('2026-03-13T18:00:00+00:00');
});

it('normalizes legacy event timestamps into utc using the city timezone', function () {
    $city = makeEventNormalizationCity();

    $event = Event::create([
        'city_id' => $city->id,
        'title' => 'Legacy Board Meeting',
        'starts_at' => '2026-03-13 18:00:00',
        'ends_at' => '2026-03-13 19:00:00',
        'all_day' => false,
        'source_hash' => sha1('legacy-board-meeting-apply'),
    ]);

    \Illuminate\Support\Facades\DB::table('events')->where('id', $event->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    $this->artisan('events:normalize-timestamps', [
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('needs_update: 1')
        ->expectsOutputToContain('updated: 1');

    expect($event->fresh()?->starts_at?->toAtomString())->toBe('2026-03-13T23:00:00+00:00')
        ->and($event->fresh()?->ends_at?->toAtomString())->toBe('2026-03-14T00:00:00+00:00');
});

it('honors the before cutoff when normalizing legacy events', function () {
    $city = makeEventNormalizationCity();

    $legacyEvent = Event::create([
        'city_id' => $city->id,
        'title' => 'Legacy Event',
        'starts_at' => '2026-03-13 18:00:00',
        'ends_at' => '2026-03-13 19:00:00',
        'all_day' => false,
        'source_hash' => sha1('legacy-event-before'),
    ]);

    $newEvent = Event::create([
        'city_id' => $city->id,
        'title' => 'New Event',
        'starts_at' => '2026-03-13 18:00:00',
        'ends_at' => '2026-03-13 19:00:00',
        'all_day' => false,
        'source_hash' => sha1('new-event-before'),
    ]);

    \Illuminate\Support\Facades\DB::table('events')->where('id', $legacyEvent->id)->update([
        'created_at' => Carbon::parse('2026-03-10 12:00:00', 'UTC'),
        'updated_at' => Carbon::parse('2026-03-10 12:00:00', 'UTC'),
    ]);

    \Illuminate\Support\Facades\DB::table('events')->where('id', $newEvent->id)->update([
        'created_at' => Carbon::parse('2026-03-20 12:00:00', 'UTC'),
        'updated_at' => Carbon::parse('2026-03-20 12:00:00', 'UTC'),
    ]);

    $this->artisan('events:normalize-timestamps', [
        '--city' => 'wichita-events',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('scanned: 1')
        ->expectsOutputToContain('updated: 1');

    expect($legacyEvent->fresh()?->starts_at?->toAtomString())->toBe('2026-03-13T23:00:00+00:00')
        ->and($newEvent->fresh()?->starts_at?->toAtomString())->toBe('2026-03-13T18:00:00+00:00');
});
