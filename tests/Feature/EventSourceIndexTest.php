<?php

use App\Livewire\Admin\EventSources\Index as EventSourceIndex;
use App\Models\EventSource;
use App\Models\User;
use Livewire\Livewire;

it('filters event sources by search term', function () {
    $user = User::factory()->create();

    EventSource::factory()->create([
        'name' => 'Alpha Source',
        'source_url' => 'https://alpha.example.com/feed',
    ]);

    EventSource::factory()->create([
        'name' => 'Beta Source',
        'source_url' => 'https://beta.example.com/feed',
    ]);

    Livewire::actingAs($user)->test(EventSourceIndex::class)
        ->set('search', 'Alpha')
        ->assertSee('Alpha Source')
        ->assertDontSee('Beta Source');
});
