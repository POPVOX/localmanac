<?php

use App\Livewire\Admin\Scrapers\Index as ScraperIndex;
use App\Models\User;
use Livewire\Livewire;

it('renders scraper columns with active before source url', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ScraperIndex::class)
        ->assertSeeInOrder([
            'Type',
            'Active',
            'Source URL',
        ]);
});
