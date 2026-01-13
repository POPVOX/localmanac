<?php

use App\Models\Event;
use App\Models\User;

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
