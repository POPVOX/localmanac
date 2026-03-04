<?php

use App\Models\User;

it('shows slowest source metrics in a collapsed section', function () {
    $admin = User::factory()->withoutTwoFactor()->superAdmin()->create();

    $this->actingAs($admin)
        ->get(route('admin.chat-sources.index'))
        ->assertOk()
        ->assertSee('Show slowest sources')
        ->assertSee('Slowest Sources (avg fetch)')
        ->assertSeeHtml('<details class="group');
});
