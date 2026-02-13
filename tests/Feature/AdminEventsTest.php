<?php

use App\Models\Event;
use App\Models\EventSource;
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
