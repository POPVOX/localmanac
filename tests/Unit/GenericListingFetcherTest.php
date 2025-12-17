<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\Fetchers\GenericListingFetcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

function makeGenericListingCity(): City
{
    return City::create([
        'name' => 'Example City',
        'slug' => 'example-city',
    ]);
}

function makeGenericListingScraper(City $city): Scraper
{
    return Scraper::create([
        'city_id' => $city->id,
        'name' => 'Generic Listing',
        'slug' => 'generic-listing',
        'type' => 'html',
        'is_enabled' => true,
        'source_url' => 'https://example.com/listing',
        'config' => [
            'profile' => 'generic_listing',
            'best_effort' => true,
            'list' => [
                'link_selector' => '.listing .story-link',
                'link_attr' => 'href',
                'max_links' => 5,
            ],
            'article' => [
                'content_selector' => '.article-content',
                'remove_selectors' => ['.paywall-note', '.sponsored'],
            ],
        ],
    ]);
}

it('extracts links and ingests article content in best-effort mode', function () {
    $city = makeGenericListingCity();
    $scraper = makeGenericListingScraper($city);

    Http::fake([
        'https://example.com/listing' => Http::response(
            file_get_contents(base_path('tests/Fixtures/generic_listing_page.html')),
            200
        ),
        'https://example.com/stories/alpha' => Http::response(
            file_get_contents(base_path('tests/Fixtures/generic_listing_article_full.html')),
            200
        ),
        'https://example.com/stories/beta' => Http::response(
            file_get_contents(base_path('tests/Fixtures/generic_listing_article_snippet.html')),
            200
        ),
    ]);

    $fetcher = new GenericListingFetcher();
    $items = $fetcher->fetch($scraper);

    expect($items)->toHaveCount(2);

    $full = $items[0];
    expect($full['city_id'])->toBe($city->id)
        ->and($full['scraper_id'])->toBe($scraper->id)
        ->and($full['title'])->toBe('Council meets on zoning')
        ->and($full['canonical_url'])->toBe('https://example.com/stories/alpha')
        ->and($full['published_at'])->toBeInstanceOf(Carbon::class)
        ->and($full['body']['raw_html'])->not->toBeNull()
        ->and($full['body']['cleaned_text'])->toContain('city council convened for a lengthy meeting')
        ->and($full['body']['cleaned_text'])->not->toContain('Subscribe for more')
        ->and($full['content_type'])->toBe('full')
        ->and($full['content_hash'])->not->toBeNull();

    $snippet = $items[1];
    expect($snippet['title'])->toBe('Budget preview')
        ->and($snippet['canonical_url'])->toBe('https://example.com/stories/beta')
        ->and($snippet['summary'])->toBe('Short preview text for the upcoming budget article.')
        ->and($snippet['body']['cleaned_text'])->toContain('finance committee released a short preview')
        ->and($snippet['body']['cleaned_text'])->not->toContain('Advertisement')
        ->and($snippet['content_type'])->toBe('snippet')
        ->and($snippet['source']['source_type'])->toBe('html')
        ->and($snippet['source']['source_url'])->toBe('https://example.com/stories/beta');
});
