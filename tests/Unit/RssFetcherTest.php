<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\Fetchers\RssFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

function createCity(): City
{
    return City::create([
        'name' => 'Test City',
        'slug' => 'test-city',
    ]);
}

it('parses rss items and prefers content encoded', function () {
    $city = createCity();

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Test RSS',
        'slug' => 'test-rss',
        'type' => 'rss',
        'source_url' => 'https://example.com/feed',
        'config' => [
            'lang' => 'en',
            'default_content_type' => 'news',
            'organization_id' => 10,
        ],
    ]);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title>Feed Title</title>
    <item>
      <title>Item One</title>
      <link>https://example.com/article-1</link>
      <guid>guid-1</guid>
      <pubDate>Wed, 11 Dec 2024 12:34:56 GMT</pubDate>
      <description><![CDATA[<p>Description text.</p>]]></description>
      <content:encoded><![CDATA[<div>Encoded <strong>content</strong>.</div>]]></content:encoded>
    </item>
    <item>
      <title>Item Two</title>
      <link>https://example.com/article-2</link>
      <guid>guid-2</guid>
      <description>Second description.</description>
    </item>
  </channel>
</rss>
XML;

    Http::fake([
        'https://example.com/feed' => Http::response($xml, 200),
    ]);

    $fetcher = new RssFetcher;
    $items = $fetcher->fetch($scraper);

    expect($items)->toHaveCount(2);

    $first = $items[0];
    expect($first['title'])->toBe('Item One')
        ->and($first['canonical_url'])->toBe('https://example.com/article-1')
        ->and($first['source']['source_url'])->toBe('https://example.com/article-1')
        ->and($first['source']['source_uid'])->toBe('guid-1')
        ->and($first['body']['raw_html'])->toBe('<div>Encoded <strong>content</strong>.</div>')
        ->and($first['summary'])->toBe('Description text.')
        ->and($first['body']['cleaned_text'])->toBe('Encoded content.')
        ->and($first['content_hash'])->not->toBeNull();

    expect($first['city_id'])->toBe($city->id)
        ->and($first['scraper_id'])->toBe($scraper->id)
        ->and($first['source']['source_type'])->toBe('rss')
        ->and($first['source']['organization_id'])->toBe(10);

    $second = $items[1];
    expect($second['body']['raw_html'])->toBe('Second description.')
        ->and($second['summary'])->toBe('Second description.');

    expect($second['canonical_url'])->toBe('https://example.com/article-2')
        ->and($second['source']['source_uid'])->toBe('guid-2')
        ->and($second['body']['cleaned_text'])->toBe('Second description.');
});
