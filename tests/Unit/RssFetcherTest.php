<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\RssCanonicalBodyHydrator;
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

it('hydrates teaser-only rss items from the canonical page', function () {
    $city = createCity();

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Wichita Updates',
        'slug' => 'wichita-updates',
        'type' => 'rss',
        'source_url' => 'https://example.com/feed',
    ]);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0">
  <channel>
    <item>
      <title>March 10 City Council Meeting recap</title>
      <link>https://example.com/article-598</link>
      <guid>guid-598</guid>
      <description><![CDATA[<p>Yesterday at the City Council meeting, the Council heard the following items:</p>]]></description>
    </item>
  </channel>
</rss>
XML;

    Http::fake([
        'https://example.com/feed' => Http::response($xml, 200),
    ]);

    $hydrator = \Mockery::mock(RssCanonicalBodyHydrator::class);
    $hydrator->shouldReceive('shouldHydrate')
        ->once()
        ->with(
            'Yesterday at the City Council meeting, the Council heard the following items:',
            'Yesterday at the City Council meeting, the Council heard the following items:',
            null,
            'https://example.com/article-598',
        )
        ->andReturnTrue();
    $hydrator->shouldReceive('hydrate')
        ->once()
        ->with('https://example.com/article-598')
        ->andReturn([
            'canonical_url' => 'https://example.com/article-598',
            'raw_html' => '<main><p>Consent Agenda approved.</p><p>Board of Bids approved.</p></main>',
            'raw_text' => 'Consent Agenda approved. Board of Bids approved.',
            'cleaned_text' => 'Consent Agenda approved. Board of Bids approved.',
            'title' => 'March 10 City Council Meeting recap',
            'renderer' => 'http',
        ]);

    $items = (new RssFetcher($hydrator))->fetch($scraper);

    expect($items)->toHaveCount(1)
        ->and($items[0]['summary'])->toBe('Yesterday at the City Council meeting, the Council heard the following items:')
        ->and($items[0]['body']['cleaned_text'])->toBe('Consent Agenda approved. Board of Bids approved.')
        ->and($items[0]['content_hash'])->toBe(hash('sha256', 'Consent Agenda approved. Board of Bids approved.'));
});
