<?php

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
    $writer = new ArticleWriter();
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
    $writer = new ArticleWriter();
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
