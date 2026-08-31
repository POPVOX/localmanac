<?php

use App\Livewire\Admin\Dashboard;
use App\Models\Article;
use App\Models\ChatSource;
use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\Scraper;
use Livewire\Livewire;

it('summarizes every location and can focus the analytics on one city', function () {
    $lawrence = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
        'state' => 'KS',
    ]);
    $jackson = City::factory()->create([
        'name' => 'Jackson',
        'slug' => 'jackson-ms',
        'state' => 'MS',
    ]);

    Scraper::create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence City News',
        'slug' => 'lawrence-city-news',
        'type' => 'rss',
        'source_url' => 'https://lawrence.example.gov/news.rss',
        'is_enabled' => true,
        'health_status' => 'healthy',
    ]);
    EventSource::factory()->create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence Events',
        'health_status' => 'healthy',
    ]);
    ChatSource::factory()->create([
        'city_id' => $jackson->id,
        'name' => 'Jackson Resident Guide',
    ]);

    Article::factory()->create([
        'city_id' => $lawrence->id,
        'title' => 'Lawrence street project',
        'created_at' => now()->subDay(),
    ]);
    Article::factory()->create([
        'city_id' => $jackson->id,
        'title' => 'Jackson city update',
        'created_at' => now()->subDay(),
    ]);
    Event::factory()->create([
        'city_id' => $lawrence->id,
        'title' => 'Lawrence council meeting',
        'starts_at' => now()->addDays(5),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSet('totalCities', 2)
        ->assertSet('totalSources', 3)
        ->assertSet('articlesLast7d', 2)
        ->assertSet('upcomingEvents', 1)
        ->assertSee('Lawrence')
        ->assertSee('Jackson')
        ->assertSee(route('admin.cities.preview', $lawrence), false)
        ->assertSee(route('admin.cities.preview', $jackson), false)
        ->set('cityId', $lawrence->id)
        ->assertSet('selectedCityName', 'Lawrence')
        ->assertSet('totalSources', 2)
        ->assertSet('articlesLast7d', 1)
        ->assertSet('upcomingEvents', 1)
        ->assertSet('citySnapshots', fn ($snapshots): bool => $snapshots->count() === 1 && $snapshots->first()->is($lawrence))
        ->assertSee('Lawrence')
        ->assertDontSee(route('admin.cities.preview', $jackson), false);
});
