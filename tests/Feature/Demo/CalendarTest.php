<?php

use App\Livewire\Demo\Calendar as CalendarComponent;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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

test('decodes html entities in calendar event text', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $selectedDate = Carbon::create(2026, 1, 13, 14, 30, 0, $city->timezone);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Rock &amp; Roll Night',
        'description' => 'We&rsquo;ll bring snacks &amp; games.',
        'starts_at' => $selectedDate,
        'ends_at' => $selectedDate->copy()->addHour(),
        'all_day' => false,
    ]);

    $response = $this->get(route('demo.calendar', [
        'date' => $selectedDate->toDateString(),
        'city_id' => $city->id,
    ]));

    $response
        ->assertSuccessful()
        ->assertSee('Rock & Roll Night')
        ->assertSee('We’ll bring snacks & games.')
        ->assertDontSee('Rock &amp; Roll Night')
        ->assertDontSee('We&rsquo;ll bring snacks &amp; games.');
});

test('treats full-day timed events as all-day on the calendar page', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $selectedDate = Carbon::create(2026, 1, 13, 0, 0, 0, $city->timezone);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Pokémon Scavenger Hunt',
        'starts_at' => $selectedDate,
        'ends_at' => $selectedDate->copy()->setTime(23, 59),
        'all_day' => false,
        'event_url' => 'https://events.example.com/pokemon-scavenger-hunt',
    ]);

    $response = $this->get(route('demo.calendar', [
        'date' => $selectedDate->toDateString(),
        'city_id' => $city->id,
    ]));

    $response
        ->assertSuccessful()
        ->assertSee('Pokémon Scavenger Hunt')
        ->assertSee('All day')
        ->assertDontSee('12:00 AM – 11:59 PM')
        ->assertSee('href="https://events.example.com/pokemon-scavenger-hunt"', false)
        ->assertSee('target="_blank"', false)
        ->assertSee('rel="noopener noreferrer"', false);
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

test('authenticated users see dashboard link before calendar link in demo navigation', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('demo.calendar'));

    $response
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Dashboard',
            'Calendar',
        ]);
});

test('calendar date updates gracefully when the picker emits null', function () {
    Carbon::setTestNow('2026-03-03 09:00:00');

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    Livewire::test(CalendarComponent::class)
        ->set('cityId', $city->id)
        ->set('selectedDate', null)
        ->assertRedirect(route('demo.calendar', [
            'date' => '2026-03-03',
            'city_id' => $city->id,
        ]));

    Carbon::setTestNow();
});
