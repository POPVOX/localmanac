<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\City;
use App\Models\Organization;
use App\Models\Scraper;
use App\Services\Analysis\HeuristicScorer;
use App\Services\Analysis\ScoreDimensions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('scores action-oriented text with timeliness and agency signals', function () {
    $city = City::create(['name' => 'Test City', 'slug' => 'test-city']);

    $organization = Organization::create([
        'city_id' => $city->id,
        'name' => 'City Government',
        'slug' => 'city-government',
        'type' => 'government',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'organization_id' => $organization->id,
        'name' => 'Gov Scraper',
        'slug' => 'gov-scraper',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Public Hearing Notice',
        'summary' => 'Public hearing scheduled.',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'The City Council will hold a public hearing on January 20, 2099. Submit comments by January 18, 2099. This zoning variance request is under review.',
        'extraction_status' => 'success',
        'extracted_at' => now(),
    ]);

    $result = app(HeuristicScorer::class)->score($article->fresh());

    expect($result['dimensions'][ScoreDimensions::AGENCY])->toBeGreaterThan(0.4)
        ->and($result['dimensions'][ScoreDimensions::TIMELINESS])->toBeGreaterThan(0.4)
        ->and($result['signals']['future_dates'])->not->toBeEmpty()
        ->and($result['signals']['jargon_hits'])->toBeGreaterThan(0);
});
