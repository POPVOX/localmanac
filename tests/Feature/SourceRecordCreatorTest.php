<?php

use App\Models\City;
use App\Models\EventSource;
use App\Models\Organization;
use App\Models\Scraper;
use App\Services\Ingestion\Assistant\SourceRecordCreator;

it('creates the correct underlying record from a verified discovery', function () {
    $city = City::factory()->create();
    $organization = Organization::create([
        'city_id' => $city->id,
        'name' => 'City Hall',
        'slug' => 'city-hall',
        'type' => 'government',
    ]);
    $creator = app(SourceRecordCreator::class);

    $article = $creator->create([
        'kind' => 'article',
        'type' => 'rss',
        'source_url' => 'https://example.gov/news.rss',
        'config' => ['feed_url' => 'https://example.gov/news.rss'],
    ], $city->id, 'City News', $organization->id, 'daily', true);

    $event = $creator->create([
        'kind' => 'event',
        'type' => 'ics',
        'source_url' => 'https://example.gov/calendar.ics',
        'config' => ['timezone' => null],
    ], $city->id, 'City Calendar', null, 'hourly', true);

    expect($article)->toBeInstanceOf(Scraper::class)
        ->and($article->organization_id)->toBe($organization->id)
        ->and($article->health_status)->toBe('healthy')
        ->and($article->slug)->toBe('city-news')
        ->and($event)->toBeInstanceOf(EventSource::class)
        ->and($event->source_type)->toBe('ics')
        ->and($event->health_status)->toBe('healthy');
});

it('prevents duplicate endpoints within a city and keeps slugs unique', function () {
    $city = City::factory()->create();
    $creator = app(SourceRecordCreator::class);

    $discovery = [
        'kind' => 'article',
        'type' => 'rss',
        'source_url' => 'https://example.gov/news.rss',
        'config' => ['feed_url' => 'https://example.gov/news.rss'],
    ];

    $creator->create($discovery, $city->id, 'City News', null, 'daily', true);

    expect(fn () => $creator->create($discovery, $city->id, 'City News Again', null, 'daily', true))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
