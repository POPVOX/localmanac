<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('unverified users are redirected to verification notice', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('verification.notice'));
});

test('verified users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()
        ->assertSee('data-testid="assistant-typing-indicator"', false)
        ->assertDontSee('data-testid="new-conversation-button"', false);
});

test('verified users can visit the scrapers admin page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.scrapers.index'));
    $response->assertOk();
});
