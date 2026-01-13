<?php

use App\Livewire\Admin\Events\Form as EventForm;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('updates event details', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Edit City',
        'slug' => 'edit-city',
        'state' => 'CO',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    $event = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Original Title',
        'starts_at' => CarbonImmutable::create(2025, 1, 10, 9, 0, 0, $city->timezone),
        'ends_at' => CarbonImmutable::create(2025, 1, 10, 11, 0, 0, $city->timezone),
        'all_day' => false,
        'location_name' => 'Old Location',
        'location_address' => 'Old Address',
        'description' => 'Old description',
        'event_url' => 'https://old.example.com',
    ]);

    Livewire::actingAs($user)->test(EventForm::class, ['event' => $event])
        ->set('title', 'Updated Title')
        ->set('cityId', $city->id)
        ->set('startsAt', '2025-01-12T10:30')
        ->set('endsAt', '2025-01-12T12:00')
        ->set('allDay', true)
        ->set('locationName', 'New Location')
        ->set('locationAddress', 'New Address')
        ->set('description', 'New description')
        ->set('eventUrl', 'https://new.example.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.events.index'));

    $event->refresh();

    expect($event->title)->toBe('Updated Title')
        ->and($event->all_day)->toBeTrue()
        ->and($event->starts_at?->shiftTimezone($city->timezone)->format('Y-m-d H:i'))->toBe('2025-01-12 10:30')
        ->and($event->ends_at?->shiftTimezone($city->timezone)->format('Y-m-d H:i'))->toBe('2025-01-12 12:00')
        ->and($event->location_name)->toBe('New Location')
        ->and($event->location_address)->toBe('New Address')
        ->and($event->description)->toBe('New description')
        ->and($event->event_url)->toBe('https://new.example.com');
});

it('validates start time format', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Format City',
        'slug' => 'format-city',
        'state' => 'UT',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    $event = Event::factory()->create([
        'city_id' => $city->id,
        'starts_at' => CarbonImmutable::create(2025, 2, 5, 9, 0, 0, $city->timezone),
    ]);

    Livewire::actingAs($user)->test(EventForm::class, ['event' => $event])
        ->set('startsAt', 'invalid')
        ->call('save')
        ->assertHasErrors(['startsAt' => 'date_format']);
});

it('preserves html description when unchanged', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Html City',
        'slug' => 'html-city',
        'state' => 'CO',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    $event = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Html Event',
        'starts_at' => CarbonImmutable::create(2025, 3, 10, 9, 0, 0, $city->timezone),
        'ends_at' => CarbonImmutable::create(2025, 3, 10, 11, 0, 0, $city->timezone),
        'description' => '<p>First paragraph.</p><p>Second paragraph.</p>',
    ]);

    Livewire::actingAs($user)->test(EventForm::class, ['event' => $event])
        ->call('save')
        ->assertHasNoErrors();

    $event->refresh();

    expect($event->description)->toBe('<p>First paragraph.</p><p>Second paragraph.</p>');
});
