<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Scraper;

it('runs in dry-run mode unless force is provided', function () {
    $city = City::create(['name' => 'Guard City', 'slug' => 'guard-city']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Guard Scraper',
        'slug' => 'guard-scraper',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => ['profile' => 'generic_listing'],
        'is_enabled' => true,
    ]);

    $good = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Budget hearing advances after long debate',
        'status' => 'published',
        'content_type' => 'full',
        'canonical_url' => 'https://example.com/2026/03/03/budget-hearing',
    ]);

    ArticleBody::create([
        'article_id' => $good->id,
        'cleaned_text' => str_repeat('The city council debated budget priorities and amendments. ', 10),
        'raw_html' => '<p>budget</p>',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $good->id,
        'source_url' => 'https://example.com/2026/03/03/budget-hearing',
        'source_type' => 'html',
    ]);

    $bad = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Kami Steinle',
        'status' => 'published',
        'content_type' => 'full',
        'canonical_url' => 'https://example.com/staff_profile/kami-steinle',
    ]);

    ArticleBody::create([
        'article_id' => $bad->id,
        'cleaned_text' => 'Assistant News Editor',
        'raw_html' => '<p>profile</p>',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $bad->id,
        'source_url' => 'https://example.com/staff_profile/kami-steinle',
        'source_type' => 'html',
    ]);

    $this->artisan('articles:prune-low-quality --scraper=guard-scraper')
        ->assertExitCode(0);

    expect(Article::query()->pluck('id')->all())
        ->toContain($good->id, $bad->id);
});

it('deletes matched articles when force is provided', function () {
    config()->set('ingestion.quality_guard.blocked_url_segments', []);
    config()->set('ingestion.quality_guard.min_words', 20);
    config()->set('ingestion.quality_guard.min_chars', 120);

    $city = City::create(['name' => 'Delete City', 'slug' => 'delete-city']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Delete Scraper',
        'slug' => 'delete-scraper',
        'type' => 'rss',
        'source_url' => 'https://example.com/feed',
        'config' => [],
        'is_enabled' => true,
    ]);

    $good = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Transit plan adds new evening service',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/story/transit-plan',
    ]);

    ArticleBody::create([
        'article_id' => $good->id,
        'cleaned_text' => str_repeat('Transit officials outlined service changes and rider impacts. ', 8),
        'raw_html' => '<p>good</p>',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $good->id,
        'source_url' => 'https://example.com/story/transit-plan',
        'source_type' => 'rss',
    ]);

    $bad = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Quick update',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/story/quick-update',
    ]);

    ArticleBody::create([
        'article_id' => $bad->id,
        'cleaned_text' => 'One sentence.',
        'raw_html' => '<p>one sentence</p>',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $bad->id,
        'source_url' => 'https://example.com/story/quick-update',
        'source_type' => 'rss',
    ]);

    $this->artisan('articles:prune-low-quality --city=delete-city --reason=min_content --force')
        ->assertExitCode(0);

    expect(Article::query()->whereKey($good->id)->exists())->toBeTrue()
        ->and(Article::query()->whereKey($bad->id)->exists())->toBeFalse();
});
