<?php

use App\Models\Article;
use App\Models\ArticleSource;
use App\Models\City;
use App\Services\Ingestion\ArticleWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

function makeCity(): City
{
    return City::create([
        'name' => 'Test City',
        'slug' => 'test-city',
    ]);
}

it('creates an article with one source', function () {
    $writer = new ArticleWriter;
    $city = makeCity();

    $writer->write([
        'city_id' => $city->id,
        'title' => 'Example Article',
        'summary' => 'A summary',
        'published_at' => now(),
        'source' => [
            'source_url' => 'https://example.com/article',
        ],
        'body' => [
            'raw_text' => 'raw',
            'cleaned_text' => 'clean',
        ],
    ]);

    expect(ArticleSource::count())->toBe(1);
});

it('does not duplicate article sources for the same URL', function () {
    $writer = new ArticleWriter;
    $city = makeCity();

    $item = [
        'city_id' => $city->id,
        'title' => 'Example Article',
        'source' => [
            'source_url' => 'https://example.com/article',
        ],
    ];

    $article = $writer->write($item);
    $writer->write($item, $article);

    expect(ArticleSource::count())->toBe(1);
});

it('stores long source uid values', function () {
    $writer = new ArticleWriter;
    $city = makeCity();
    $sourceUid = str_repeat('https://example.com/long-guid/', 15);

    $writer->write([
        'city_id' => $city->id,
        'title' => 'Long Source UID Article',
        'source' => [
            'source_url' => 'https://example.com/article-long',
            'source_type' => 'rss',
            'source_uid' => $sourceUid,
        ],
    ]);

    $source = ArticleSource::first();

    expect(strlen($sourceUid))->toBeGreaterThan(255)
        ->and($source)->not()->toBeNull()
        ->and($source->source_uid)->toBe($sourceUid);
});

it('does not overwrite existing summary with null on re-scrape', function () {
    $writer = new ArticleWriter;
    $city = makeCity();

    $article = $writer->write([
        'city_id' => $city->id,
        'title' => 'Good Title',
        'summary' => 'Existing summary',
        'source' => [
            'source_url' => 'https://example.com/article-summary',
        ],
    ]);

    $writer->write([
        'city_id' => $city->id,
        'title' => 'Good Title',
        'summary' => null,
        'source' => [
            'source_url' => 'https://example.com/article-summary',
        ],
    ], $article);

    expect($article->fresh()?->summary)->toBe('Existing summary');
});

it('keeps strong existing title when incoming title is weak', function () {
    $writer = new ArticleWriter;
    $city = makeCity();

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Wichita Valley Center Flood Control Project Repairs',
        'summary' => 'Existing summary',
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://example.com/article-title',
    ]);

    $writer->write([
        'city_id' => $city->id,
        'title' => '458-2024-085587_LegalNotice (PDF)',
        'summary' => null,
        'source' => [
            'source_url' => 'https://example.com/article-title',
        ],
    ], $article);

    expect($article->fresh()?->title)->toBe('Wichita Valley Center Flood Control Project Repairs');
});
