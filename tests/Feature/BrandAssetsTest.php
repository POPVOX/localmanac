<?php

test('head includes the image-based favicon set', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('href="'.asset('images/favicon-32x32.png').'"', false)
        ->assertSee('href="'.asset('images/favicon-16x16.png').'"', false)
        ->assertSee('href="'.asset('images/apple-touch-icon.png').'"', false)
        ->assertSee('href="'.asset('images/favicon.ico').'"', false)
        ->assertSee('href="'.asset('images/site.webmanifest').'"', false);
});

test('auth pages render the shared logo image', function () {
    $response = $this->get(route('login'));

    $response
        ->assertOk()
        ->assertSee('src="'.asset('images/logo.png').'"', false);
});

test('landing page renders the shared logo image', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('src="'.asset('images/logo.png').'"', false);
});

test('dashboard renders the shared logo image', function () {
    $user = \App\Models\User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('src="'.asset('images/logo.png').'"', false);
});
