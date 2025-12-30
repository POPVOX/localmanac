<?php

use App\Models\User;

test('admin pages include the flux toast handler', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('admin.cities.create'));

    $response
        ->assertOk()
        ->assertSee('window.showFluxToast', false)
        ->assertSee('livewire:navigated', false);
});
