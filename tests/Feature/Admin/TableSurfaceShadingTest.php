<?php

use App\Models\User;

test('admin organizations index uses shaded table surface', function () {
    $user = User::factory()->withoutTwoFactor()->superAdmin()->create();

    $response = $this->actingAs($user)->get(route('admin.organizations.index'));

    $response
        ->assertOk()
        ->assertSee('bg-zinc-100/60 dark:bg-zinc-800/45');
});
