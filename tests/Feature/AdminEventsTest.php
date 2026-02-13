<?php

use App\Livewire\Admin\Events\Index as EventsIndex;
use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use App\Models\User;
use Livewire\Livewire;

test('verified users can visit the event sources admin page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    config(['app.name' => 'LocAlmanac']);

    $response = $this->get(route('admin.event-sources.index'));

    $response->assertOk()
        ->assertSee('LocAlmanac');
});

test('verified users can visit the events admin page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.events.index'));

    $response->assertOk();
});

test('verified users can visit an event detail page', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('admin.events.show', $event));

    $response->assertOk();
});

test('verified users can visit an event edit page', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('admin.events.edit', $event));

    $response->assertOk();
});

test('events admin page filters by search term', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();

    $matchingEvent = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Little Miss Moonshine',
        'starts_at' => now()->addDays(2),
    ]);

    $otherEvent = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'County Fair Cleanup',
        'starts_at' => now()->addDays(2),
    ]);

    $source = EventSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Visit Wichita',
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $matchingEvent->id,
        'event_source_id' => $source->id,
    ]);

    EventSourceItem::factory()->create([
        'event_id' => $otherEvent->id,
    ]);

    Livewire::actingAs($user)->test(EventsIndex::class)
        ->set('cityId', $city->id)
        ->set('search', 'Moonshine')
        ->assertSee('Little Miss Moonshine')
        ->assertDontSee('County Fair Cleanup');
});

test('events admin page sorts by title', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Zulu Event',
        'starts_at' => now()->addDays(3),
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Alpha Event',
        'starts_at' => now()->addDays(2),
    ]);

    $response = $this->actingAs($user)->get(route('admin.events.index', [
        'cityId' => $city->id,
        'startDate' => now()->toDateString(),
        'endDate' => now()->addDays(30)->toDateString(),
        'sortField' => 'events.title',
        'sortDirection' => 'asc',
    ]));

    $response->assertOk()
        ->assertSeeInOrder(['Alpha Event', 'Zulu Event']);
});

test('event source details mask auth tokens in config preview', function () {
    $user = User::factory()->create();
    $token = '7b501fa364a55caef77b7f775e7a4941';

    $source = EventSource::factory()->create([
        'source_type' => 'json_api',
        'config' => [
            'profile' => 'visit_wichita_simpleview',
            'json' => [
                'root_path' => 'docs.docs',
            ],
            'auth' => [
                'token' => $token,
            ],
        ],
    ]);

    $this->actingAs($user);

    $response = $this->get(route('admin.event-sources.show', $source));

    $response->assertOk()
        ->assertDontSee($token)
        ->assertSee('********...4941');
});
