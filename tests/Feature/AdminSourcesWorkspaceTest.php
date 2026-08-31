<?php

use App\Livewire\Admin\Sources\Index;
use App\Models\ChatSource;
use App\Models\City;
use App\Models\EventSource;
use App\Models\Scraper;
use Livewire\Livewire;

it('combines source types into a location-aware inventory with preview links', function () {
    $lawrence = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
    ]);
    $jackson = City::factory()->create([
        'name' => 'Jackson',
        'slug' => 'jackson-ms',
    ]);

    Scraper::create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence News Feed',
        'slug' => 'lawrence-news-feed',
        'type' => 'rss',
        'source_url' => 'https://lawrence.example.gov/feed',
        'is_enabled' => true,
        'health_status' => 'healthy',
    ]);
    EventSource::factory()->create([
        'city_id' => $jackson->id,
        'name' => 'Jackson Calendar Feed',
        'source_url' => 'https://jackson.example.gov/calendar',
        'health_status' => 'unhealthy',
    ]);
    ChatSource::factory()->create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence Answer Library',
        'source_url' => 'https://lawrence.example.gov/residents',
    ]);

    Livewire::test(Index::class)
        ->assertViewHas('categoryCounts', [
            'article' => 1,
            'event' => 1,
            'chat' => 1,
        ])
        ->assertViewHas('attentionCount', 1)
        ->assertSee('Lawrence News Feed')
        ->assertSee('Jackson Calendar Feed')
        ->assertSee('Lawrence Answer Library')
        ->assertSee(route('admin.cities.preview', $lawrence), false)
        ->assertSee(route('admin.cities.preview', $jackson), false)
        ->set('cityId', $lawrence->id)
        ->assertSee('Lawrence News Feed')
        ->assertSee('Lawrence Answer Library')
        ->assertDontSee('Jackson Calendar Feed')
        ->set('kind', 'chat')
        ->assertSee('Lawrence Answer Library')
        ->assertDontSee('Lawrence News Feed');
});
