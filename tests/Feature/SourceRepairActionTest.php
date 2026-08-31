<?php

use App\Livewire\Admin\EventSources\Index as EventSourcesIndex;
use App\Livewire\Admin\Scrapers\Index as ScrapersIndex;
use App\Models\City;
use App\Models\EventSource;
use App\Models\Scraper;
use App\Models\User;
use Livewire\Livewire;

it('applies a verified article source repair from the admin list', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'City News',
        'slug' => 'city-news',
        'type' => 'html',
        'source_url' => 'https://example.gov/news',
        'config' => ['profile' => 'generic_listing'],
        'is_enabled' => true,
        'frequency' => 'daily',
        'health_status' => 'unhealthy',
        'health_error' => 'Old selector failed',
        'repair_proposal' => [
            'kind' => 'article',
            'type' => 'rss',
            'source_url' => 'https://example.gov/news.rss',
            'config' => ['feed_url' => 'https://example.gov/news.rss'],
            'summary' => 'Use the discovered feed.',
        ],
    ]);

    Livewire::actingAs($user)->test(ScrapersIndex::class)
        ->assertSee('Apply verified repair')
        ->call('applyRepair', $scraper->id);

    $scraper->refresh();

    expect($scraper->type)->toBe('rss')
        ->and($scraper->source_url)->toBe('https://example.gov/news.rss')
        ->and($scraper->health_status)->toBe('unknown')
        ->and($scraper->repair_proposal)->toBeNull();
});

it('applies a verified event source repair from the admin list', function () {
    $user = User::factory()->create();
    $source = EventSource::factory()->create([
        'source_type' => 'html',
        'source_url' => 'https://example.gov/events',
        'config' => ['profile' => 'generic_html_list'],
        'health_status' => 'unhealthy',
        'health_error' => 'Calendar markup changed',
        'repair_proposal' => [
            'kind' => 'event',
            'type' => 'ics',
            'source_url' => 'https://example.gov/calendar.ics',
            'config' => ['timezone' => null],
            'summary' => 'Use the discovered calendar feed.',
        ],
    ]);

    Livewire::actingAs($user)->test(EventSourcesIndex::class)
        ->assertSee('Apply verified repair')
        ->call('applyRepair', $source->id);

    $source->refresh();

    expect($source->source_type)->toBe('ics')
        ->and($source->source_url)->toBe('https://example.gov/calendar.ics')
        ->and($source->health_status)->toBe('unknown')
        ->and($source->repair_proposal)->toBeNull();
});
