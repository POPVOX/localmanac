<?php

use App\Models\User;

test('home page does not force dark mode at the document root', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('class="dark"', false);
});

test('appearance settings route is unavailable', function () {
    expect(\Illuminate\Support\Facades\Route::has('appearance.edit'))->toBeFalse();

    $this->get('/settings/appearance')->assertNotFound();
});

test('authenticated dashboard does not force dark mode at the document root', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('class="dark"', false);
});

test('admin dashboard does not force dark mode at the document root', function () {
    $user = User::factory()->withoutTwoFactor()->superAdmin()->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('class="dark"', false);
});
