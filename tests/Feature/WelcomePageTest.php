<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('guest sees landing page sections', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('LocAlmanac')
        ->assertSee('Today in your city')
        ->assertSee('Features')
        ->assertSee('How it works')
        ->assertSee('Get local info in one place.')
        ->assertSee('Coverage varies by city and source availability.')
        ->assertDontSee('Search')
        ->assertDontSee('Questions');

    if (Route::has('register')) {
        $response->assertSee('Create account');
    }

    if (Route::has('login')) {
        $response->assertSee('Log in');
    }
});

test('authenticated users see dashboard ctas', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('Search')
        ->assertSee('Go to dashboard')
        ->assertDontSee('Create account')
        ->assertDontSee('Log in');
});
