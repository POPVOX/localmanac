<?php

use App\Models\City;
use App\Models\Event;
use Illuminate\Support\Carbon;

test('shows events for the selected date only', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $selectedDate = Carbon::create(2026, 1, 13, 9, 0, 0, $city->timezone);
    $otherDate = Carbon::create(2026, 1, 14, 9, 0, 0, $city->timezone);

    $event = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Downtown Meetup',
        'starts_at' => $selectedDate,
        'ends_at' => $selectedDate->copy()->addHour(),
        'all_day' => false,
    ]);

    $allDayEvent = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'All Day Fair',
        'starts_at' => $selectedDate->copy()->startOfDay(),
        'ends_at' => null,
        'all_day' => true,
    ]);

    $otherEvent = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Tomorrow Event',
        'starts_at' => $otherDate,
        'ends_at' => $otherDate->copy()->addHour(),
        'all_day' => false,
    ]);

    $response = $this->get(route('demo.calendar', [
        'date' => $selectedDate->toDateString(),
        'city_id' => $city->id,
    ]));

    $response
        ->assertSuccessful()
        ->assertSee($event->title)
        ->assertSee($allDayEvent->title)
        ->assertSee('All day')
        ->assertDontSee($otherEvent->title);
});

test('shows empty state when no events exist for the selected date', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $selectedDate = Carbon::create(2026, 1, 13, 9, 0, 0, $city->timezone);

    $response = $this->get(route('demo.calendar', [
        'date' => $selectedDate->toDateString(),
        'city_id' => $city->id,
    ]));

    $response
        ->assertSuccessful()
        ->assertSee('No events scheduled for this day.');
});
