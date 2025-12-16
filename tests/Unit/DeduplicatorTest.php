<?php

use App\Models\Article;
use App\Models\ArticleSource;
use App\Models\City;
use App\Services\Ingestion\Deduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

function makeTestCity(): City
{
    return City::create([
        'name' => 'Test City',
        'slug' => 'test-city',
    ]);
}

function makeArticle(City $city, array $overrides = []): Article
{
    return Article::create(array_merge([
        'city_id' => $city->id,
        'title' => fake()->sentence(),
        'content_type' => 'web',
        'status' => 'published',
    ], $overrides));
}

it('matches by canonical url first', function () {
    $deduplicator = new Deduplicator();
    $city = makeTestCity();

    $canonicalArticle = makeArticle($city, [
        'canonical_url' => 'https://example.com/target',
        'content_hash' => 'shared-hash',
    ]);

    makeArticle($city, [
        'content_hash' => 'shared-hash',
    ]);

    $item = [
        'city_id' => $city->id,
        'canonical_url' => 'https://example.com/target',
        'content_hash' => 'shared-hash',
        'source' => [
            'source_uid' => 'other-uid',
        ],
    ];

    $found = $deduplicator->findExisting($item);

    expect($found?->id)->toBe($canonicalArticle->id);
});

it('matches by source uid when canonical url is absent', function () {
    $deduplicator = new Deduplicator();
    $city = makeTestCity();

    $article = makeArticle($city);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/source',
        'source_uid' => 'source-123',
    ]);

    $item = [
        'city_id' => $city->id,
        'source' => [
            'source_uid' => 'source-123',
        ],
    ];

    $found = $deduplicator->findExisting($item);

    expect($found?->id)->toBe($article->id);
});

it('matches by content hash when no higher priority match exists', function () {
    $deduplicator = new Deduplicator();
    $city = makeTestCity();

    $article = makeArticle($city, [
        'content_hash' => 'hash-abc',
    ]);

    $item = [
        'city_id' => $city->id,
        'content_hash' => 'hash-abc',
    ];

    $found = $deduplicator->findExisting($item);

    expect($found?->id)->toBe($article->id);
});

it('prioritizes source uid over content hash when canonical url is missing', function () {
    $deduplicator = new Deduplicator();
    $city = makeTestCity();

    $sourceUidArticle = makeArticle($city, [
        'content_hash' => 'hash-shared',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $sourceUidArticle->id,
        'source_url' => 'https://example.com/source-uid',
        'source_uid' => 'uid-1',
    ]);

    makeArticle($city, [
        'content_hash' => 'hash-shared',
    ]);

    $item = [
        'city_id' => $city->id,
        'content_hash' => 'hash-shared',
        'source' => [
            'source_uid' => 'uid-1',
        ],
    ];

    $found = $deduplicator->findExisting($item);

    expect($found?->id)->toBe($sourceUidArticle->id);
});

it('throws when city_id is missing', function () {
    $deduplicator = new Deduplicator();

    $deduplicator->findExisting([]);
})->throws(InvalidArgumentException::class);
