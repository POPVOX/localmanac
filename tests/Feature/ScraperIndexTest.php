<?php

use App\Livewire\Admin\Scrapers\Index as ScraperIndex;
use App\Models\User;
use Livewire\Livewire;

it('renders scraper columns with active before scraper name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ScraperIndex::class)
        ->assertSeeInOrder([
            'Scraper',
            'Active',
            'Last scraped',
        ]);
});
