<?php

use App\Models\User;

it('allows verified users to access the user dashboard', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

it('allows guests to browse the user dashboard', function () {
    $this->get('/dashboard')
        ->assertOk();
});
