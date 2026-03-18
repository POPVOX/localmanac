<?php

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Scraper;
use App\Services\Extraction\Enricher;
use App\Services\Ingestion\RssCanonicalBodyHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rehydrates teaser-only rss articles from their canonical page', function () {
    config()->set('enrichment.enabled', true);
    config()->set('enrichment.model', 'test-model');
    config()->set('enrichment.prompt_version', 'test-prompt');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Updates',
        'slug' => 'updates',
        'type' => 'rss',
        'source_url' => 'https://example.com/feed',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'March 10 City Council Meeting recap',
        'summary' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/article-598',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_html' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'raw_text' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'cleaned_text' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/article-598',
        'source_type' => 'rss',
        'source_uid' => 'guid-598',
        'accessed_at' => now(),
    ]);

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'whats_happening' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'why_it_matters' => null,
        'source' => 'analysis_llm',
    ]);

    $hydrator = \Mockery::mock(RssCanonicalBodyHydrator::class);
    $hydrator->shouldReceive('shouldHydrate')
        ->once()
        ->with(
            'Yesterday at the City Council meeting, the Council heard the following items:',
            'Yesterday at the City Council meeting, the Council heard the following items:',
            'Yesterday at the City Council meeting, the Council heard the following items:',
            'https://example.com/article-598',
        )
        ->andReturnTrue();
    $hydrator->shouldReceive('hydrate')
        ->once()
        ->with('https://example.com/article-598')
        ->andReturn([
            'canonical_url' => 'https://example.com/article-598',
            'raw_html' => '<main><p>Consent Agenda approved 7-0.</p><p>Board of Bids and Contracts approved 7-0.</p></main>',
            'raw_text' => "Consent Agenda approved 7-0.\n\nBoard of Bids and Contracts approved 7-0.",
            'cleaned_text' => "Yesterday at the City Council meeting, the Council heard the following items:\n\n* Consent Agenda approved 7-0\n\n* Board of Bids and Contracts approved 7-0",
            'title' => 'March 10 City Council Meeting recap',
            'renderer' => 'http',
        ]);

    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);
    app()->instance(Enricher::class, new class extends Enricher
    {
        public function __construct() {}

        public function enrich(Article $article): array
        {
            return [
                'analysis' => [
                    'dimensions' => [
                        'comprehensibility' => 0.8,
                        'orientation' => 0.7,
                        'representation' => 0.6,
                        'agency' => 0.5,
                        'relevance' => 0.7,
                        'timeliness' => 0.8,
                    ],
                    'justifications' => [
                        'comprehensibility' => 'Clear summary.',
                        'orientation' => 'Explains the council actions.',
                        'representation' => 'Includes affected actions.',
                        'agency' => 'No immediate action requested.',
                        'relevance' => 'Covers city council approvals.',
                        'timeliness' => 'Recent recap.',
                    ],
                    'opportunities' => [],
                    'confidence' => 0.82,
                ],
                'enrichment' => [
                    'people' => [],
                    'organizations' => [],
                    'locations' => [],
                    'keywords' => [],
                    'issue_areas' => [],
                    'confidence' => 0.75,
                ],
                'process_timeline' => [
                    'items' => [],
                    'current_key' => null,
                ],
                'explainer' => [
                    'whats_happening' => 'The council approved most consent-agenda items, signed off on bids and contracts, and moved forward public improvement requests.',
                    'why_it_matters' => 'These votes advance city contracts and public works decisions that affect Wichita projects and services.',
                    'key_details' => null,
                    'what_to_watch' => null,
                    'evidence' => null,
                ],
                'confidence' => 0.8,
            ];
        }
    });

    $this->artisan('articles:rehydrate-rss-bodies --limit=1')
        ->expectsOutputToContain('Rehydrated 1 of 1 article(s).')
        ->assertExitCode(0);

    $article->refresh();
    $article->load(['body', 'explainer']);

    expect($article->body?->cleaned_text)
        ->toContain('Consent Agenda approved 7-0')
        ->toContain('Board of Bids and Contracts approved 7-0');

    expect($article->summary)
        ->toBe('The council approved most consent-agenda items, signed off on bids and contracts, and moved forward public improvement requests.');

    expect($article->explainer?->whats_happening)
        ->toBe('The council approved most consent-agenda items, signed off on bids and contracts, and moved forward public improvement requests.')
        ->and($article->explainer?->why_it_matters)
        ->toBe('These votes advance city contracts and public works decisions that affect Wichita projects and services.')
        ->and($article->explainer?->source)
        ->toBe('analysis_llm');

    expect(ArticleAnalysis::query()->where('article_id', $article->id)->exists())->toBeTrue();
});
