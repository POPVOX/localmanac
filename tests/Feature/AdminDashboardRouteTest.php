<?php

use App\Models\User;

it('denies regular users from accessing the admin dashboard route', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/admin/dashboard')
        ->assertForbidden();
});

it('allows super admins to access the admin dashboard route', function () {
    $user = User::factory()->withoutTwoFactor()->superAdmin()->create();

    $this->actingAs($user)
        ->get('/admin/dashboard')
        ->assertOk();
});
