<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Organization;
use App\Models\Scraper;

it('builds the searchable payload with body and metadata', function () {
    $city = City::create(['name' => 'Search City', 'slug' => 'search-city']);

    $organization = Organization::create([
        'city_id' => $city->id,
        'name' => 'Search Org',
        'slug' => 'search-org',
        'type' => 'news_media',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'organization_id' => $organization->id,
        'name' => 'Search Scraper',
        'slug' => 'search-scraper',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [],
    ]);

    $publishedAt = now()->startOfSecond();

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Search Title',
        'summary' => 'Search Summary',
        'published_at' => $publishedAt,
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('Body text ', 3000),
        'extraction_status' => 'success',
        'extracted_at' => now(),
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'organization_id' => $organization->id,
        'source_url' => 'https://example.com/source',
        'source_type' => 'web',
        'source_uid' => 'source-uid',
        'accessed_at' => now(),
    ]);

    $payload = $article->fresh()->toSearchableArray();

    expect($payload['id'])->toBe($article->id)
        ->and($payload['id'])->toBeInt()
        ->and($payload['city_id'])->toBe($city->id)
        ->and($payload['city_id'])->toBeInt()
        ->and($payload['title'])->toBe('Search Title')
        ->and($payload['summary'])->toBe('Search Summary')
        ->and($payload['organization_id'])->toBe($organization->id)
        ->and($payload['organization_id'])->toBeInt()
        ->and($payload['scraper_id'])->toBe($scraper->id)
        ->and($payload['scraper_id'])->toBeInt()
        ->and($payload['source_url'])->toBe('https://example.com/source')
        ->and($payload['extraction_status'])->toBe('success')
        ->and($payload['published_at'])->toBe($publishedAt->toAtomString())
        ->and($payload['created_at'])->not->toBeNull()
        ->and(mb_strlen($payload['body']))->toBeLessThanOrEqual(20000);
});
