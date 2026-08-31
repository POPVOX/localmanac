<?php

use App\Livewire\Admin\Scrapers\Index as ScraperIndex;
use App\Livewire\Admin\Scrapers\Show as ScraperShow;
use App\Models\City;
use App\Models\Scraper;
use App\Models\ScraperRun;
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

it('flags invalid scraper sources with a direct update prompt', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Wichita', 'slug' => 'wichita']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Retired Listing',
        'slug' => 'retired-listing',
        'type' => 'html',
        'is_enabled' => false,
        'source_url' => 'https://old.example.com/listing',
        'config' => [],
    ]);

    ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'failed',
        'error_message' => 'Failed to fetch listing page',
        'finished_at' => now(),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    Livewire::actingAs($user)->test(ScraperIndex::class)
        ->assertSee('Source needs update')
        ->assertSee('Source invalid')
        ->assertSee('Update scraper')
        ->assertSee(route('admin.scrapers.edit', $scraper), false);
});

it('keeps worker timeouts separate from invalid source warnings', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Lawrence', 'slug' => 'lawrence']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Healthy Source',
        'slug' => 'healthy-source',
        'type' => 'rss',
        'is_enabled' => true,
        'source_url' => 'https://example.com/feed',
        'config' => [],
    ]);

    ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'failed',
        'error_message' => 'Run timed out before the worker started.',
        'finished_at' => now(),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    Livewire::actingAs($user)->test(ScraperIndex::class)
        ->assertSee('Healthy Source')
        ->assertSee('Failed')
        ->assertDontSee('Source needs update')
        ->assertDontSee('Source invalid');
});

it('explains the invalid source on the scraper detail view', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Jackson', 'slug' => 'jackson']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Blocked Listing',
        'slug' => 'blocked-listing',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/events',
        'config' => [],
    ]);

    ScraperRun::create([
        'scraper_id' => $scraper->id,
        'city_id' => $city->id,
        'status' => 'failed',
        'error_message' => 'Listing page is blocked by anti-bot protection.',
        'finished_at' => now(),
        'items_found' => 0,
        'items_created' => 0,
        'items_updated' => 0,
    ]);

    Livewire::actingAs($user)->test(ScraperShow::class, ['scraper' => $scraper])
        ->assertSee('Source may no longer be valid')
        ->assertSee('The source now blocks automated access.')
        ->assertSee('Update scraper')
        ->assertSee(route('admin.scrapers.edit', $scraper), false);
});
