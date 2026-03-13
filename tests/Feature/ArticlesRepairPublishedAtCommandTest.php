<?php

use App\Models\Article;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Scraper;
use Illuminate\Support\Facades\Http;

function makeRepairCity(): City
{
    return City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);
}

function makeGenericListingRepairScraper(City $city, array $config = []): Scraper
{
    return Scraper::create([
        'city_id' => $city->id,
        'name' => 'The Sunflower',
        'slug' => 'the-sunflower',
        'type' => 'html',
        'source_url' => 'https://example.com/news',
        'is_enabled' => true,
        'config' => array_merge([
            'profile' => 'generic_listing',
        ], $config),
    ]);
}

it('audits feed-backed published_at repairs without writing changes', function () {
    $city = makeRepairCity();
    $scraper = makeGenericListingRepairScraper($city, [
        'feed_url' => 'https://example.com/feed.xml',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Budget vote advances',
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://example.com/2026/03/13/budget-vote-advances',
        'published_at' => '2026-03-13 13:00:00',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/2026/03/13/budget-vote-advances',
        'source_type' => 'html',
        'accessed_at' => now(),
    ]);

    Http::fake([
        'https://example.com/feed.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8" ?>
            <rss version="2.0">
                <channel>
                    <item>
                        <title>Budget vote advances</title>
                        <link>https://example.com/2026/03/13/budget-vote-advances</link>
                        <pubDate>Fri, 13 Mar 2026 14:50:18 +0000</pubDate>
                    </item>
                </channel>
            </rss>
            XML, 200),
    ]);

    $this->artisan('articles:repair-published-at', ['--scraper' => 'the-sunflower'])
        ->assertSuccessful()
        ->expectsOutputToContain('Audit mode only. Pass --apply to persist changes.')
        ->expectsOutputToContain('needs_update: 1')
        ->expectsOutputToContain('updated: 0');

    expect($article->fresh()?->published_at?->toDateTimeString())->toBe('2026-03-13 13:00:00');
});

it('updates published_at from a configured feed when apply is set', function () {
    $city = makeRepairCity();
    $scraper = makeGenericListingRepairScraper($city, [
        'feed_url' => 'https://example.com/feed.xml',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Budget vote advances',
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://example.com/2026/03/13/budget-vote-advances',
        'published_at' => '2026-03-13 13:00:00',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/2026/03/13/budget-vote-advances',
        'source_type' => 'html',
        'accessed_at' => now(),
    ]);

    Http::fake([
        'https://example.com/feed.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8" ?>
            <rss version="2.0">
                <channel>
                    <item>
                        <title>Budget vote advances</title>
                        <link>https://example.com/2026/03/13/budget-vote-advances</link>
                        <pubDate>Fri, 13 Mar 2026 14:50:18 +0000</pubDate>
                    </item>
                </channel>
            </rss>
            XML, 200),
    ]);

    $this->artisan('articles:repair-published-at', ['--scraper' => 'the-sunflower', '--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-03-13T14:50:18+00:00');
});

it('discovers a feed from the scraper source page when none is configured', function () {
    $city = makeRepairCity();
    $scraper = makeGenericListingRepairScraper($city);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Student senate debates budget',
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'published_at' => '2026-03-13 13:00:00',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/2026/03/13/student-senate-debates-budget',
        'source_type' => 'html',
        'accessed_at' => now(),
    ]);

    Http::fake([
        'https://example.com/news' => Http::response(<<<'HTML'
            <html>
                <head>
                    <link rel="alternate" type="application/rss+xml" href="/feed.xml" />
                </head>
                <body></body>
            </html>
            HTML, 200),
        'https://example.com/feed.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8" ?>
            <rss version="2.0">
                <channel>
                    <item>
                        <title>Student senate debates budget</title>
                        <link>https://example.com/2026/03/13/student-senate-debates-budget</link>
                        <pubDate>Fri, 13 Mar 2026 09:53:12 +0000</pubDate>
                    </item>
                </channel>
            </rss>
            XML, 200),
    ]);

    $this->artisan('articles:repair-published-at', ['--scraper' => 'the-sunflower', '--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('feed: https://example.com/feed.xml')
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-03-13T09:53:12+00:00');
});
