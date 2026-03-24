<?php

use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
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

it('shows last run state and actions for chat sources', function () {
    $admin = User::factory()->withoutTwoFactor()->superAdmin()->create();
    $source = ChatSource::factory()->create([
        'name' => 'Permit Help',
    ]);

    ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $source->id,
        'status' => 'failed',
        'error_message' => 'Crawler failed.',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.chat-sources.index'))
        ->assertOk()
        ->assertSee('Permit Help')
        ->assertSee('Last run')
        ->assertSee('View')
        ->assertSee('Run')
        ->assertSee('Failed')
        ->assertSee('whitespace-nowrap');
});
