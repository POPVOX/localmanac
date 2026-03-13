<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Scraper;

function makePurgeCity(string $slug = 'wichita'): City
{
    return City::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'timezone' => 'America/Chicago',
    ]);
}

function makePurgeScraper(City $city, string $name, string $slug, array $config = [], string $type = 'html'): Scraper
{
    return Scraper::create([
        'city_id' => $city->id,
        'name' => $name,
        'slug' => $slug,
        'type' => $type,
        'source_url' => "https://example.com/{$slug}",
        'is_enabled' => true,
        'config' => $config,
    ]);
}

function makePurgeArticle(City $city, Scraper $scraper, string $title): Article
{
    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => $title,
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://example.com/'.str()->slug($title),
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'Article body',
        'extracted_at' => now(),
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/'.str()->slug($title),
        'source_type' => 'html',
        'accessed_at' => now(),
    ]);

    return $article;
}

it('audits scraper article counts without deleting rows', function () {
    $city = makePurgeCity();
    $sunflower = makePurgeScraper($city, 'The Sunflower', 'the-sunflower', ['profile' => 'generic_listing']);
    makePurgeArticle($city, $sunflower, 'Budget vote advances');
    makePurgeArticle($city, $sunflower, 'Student senate debates budget');

    $this->artisan('articles:purge-scraper-data', ['--scraper' => ['the-sunflower']])
        ->assertSuccessful()
        ->expectsOutputToContain('Audit mode only. Pass --apply to delete matching articles.')
        ->expectsOutputToContain('The Sunflower (the-sunflower)')
        ->expectsOutputToContain('articles: 2')
        ->expectsOutputToContain('deleted: 0');

    expect(Article::count())->toBe(2)
        ->and(ArticleBody::count())->toBe(2)
        ->and(ArticleSource::count())->toBe(2);
});

it('deletes only the targeted scraper articles and cascades dependent rows', function () {
    $city = makePurgeCity();
    $sunflower = makePurgeScraper($city, 'The Sunflower', 'the-sunflower', ['profile' => 'generic_listing']);
    $voice = makePurgeScraper($city, 'The Voice Wichita', 'the-voice-wichita', ['profile' => 'generic_listing']);

    $deletedArticle = makePurgeArticle($city, $sunflower, 'Budget vote advances');
    $keptArticle = makePurgeArticle($city, $voice, 'Neighborhood grant expands');

    $this->artisan('articles:purge-scraper-data', ['--scraper' => ['the-sunflower'], '--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('articles: 1')
        ->expectsOutputToContain('deleted: 1');

    expect(Article::query()->whereKey($deletedArticle->id)->exists())->toBeFalse()
        ->and(ArticleBody::query()->where('article_id', $deletedArticle->id)->exists())->toBeFalse()
        ->and(ArticleSource::query()->where('article_id', $deletedArticle->id)->exists())->toBeFalse()
        ->and(Article::query()->whereKey($keptArticle->id)->exists())->toBeTrue();
});

it('can target generic listing scrapers within a city', function () {
    $wichita = makePurgeCity('wichita');
    $topeka = makePurgeCity('topeka');

    $sunflower = makePurgeScraper($wichita, 'The Sunflower', 'the-sunflower', ['profile' => 'generic_listing']);
    $rssScraper = makePurgeScraper($wichita, 'Wichita RSS', 'wichita-rss', [], 'rss');
    $topekaScraper = makePurgeScraper($topeka, 'Topeka Generic', 'topeka-generic', ['profile' => 'generic_listing']);

    makePurgeArticle($wichita, $sunflower, 'Wichita generic article');
    makePurgeArticle($wichita, $rssScraper, 'Wichita rss article');
    makePurgeArticle($topeka, $topekaScraper, 'Topeka generic article');

    $this->artisan('articles:purge-scraper-data', ['--generic-listing' => true, '--city' => 'wichita', '--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('The Sunflower (the-sunflower)')
        ->expectsOutputToContain('articles: 1')
        ->expectsOutputToContain('deleted: 1');

    expect(Article::query()->where('scraper_id', $sunflower->id)->count())->toBe(0)
        ->and(Article::query()->where('scraper_id', $rssScraper->id)->count())->toBe(1)
        ->and(Article::query()->where('scraper_id', $topekaScraper->id)->count())->toBe(1);
});
