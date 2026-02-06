<?php

use App\Models\User;

it('allows verified users to access the admin dashboard route', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/admin/dashboard')
        ->assertOk();
});
