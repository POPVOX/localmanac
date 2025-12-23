<?php

use App\Jobs\ScoreArticleWithLlm;
use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleBody;
use App\Models\City;
use App\Models\Organization;
use App\Models\Scraper;
use App\Services\Analysis\AnalysisGate;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\LlmScorer;
use App\Services\Analysis\ScoreDimensions;

use function Pest\Laravel\mock;

function makeArticleWithAnalysis(array $finalScores, float $civicScore, string $orgType): Article
{
    $city = City::create(['name' => 'LLM City', 'slug' => 'llm-city']);

    $organization = Organization::create([
        'city_id' => $city->id,
        'name' => 'LLM Org',
        'slug' => 'llm-org',
        'type' => $orgType,
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'organization_id' => $organization->id,
        'name' => 'LLM Scraper',
        'slug' => 'llm-scraper',
        'type' => 'html',
        'source_url' => 'https://example.com',
        'config' => [],
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'LLM Article',
        'summary' => 'Summary text',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'Body text for LLM scoring.',
        'extraction_status' => 'success',
        'extracted_at' => now(),
    ]);

    ArticleAnalysis::create([
        'article_id' => $article->id,
        'score_version' => 'crf_v1',
        'status' => 'heuristics_done',
        'final_scores' => $finalScores,
        'civic_relevance_score' => $civicScore,
        'last_scored_at' => now(),
    ]);

    return $article;
}

it('skips llm scoring when analysis gate conditions are not met', function () {
    config()->set('analysis.llm.enabled', false);

    $article = makeArticleWithAnalysis([
        ScoreDimensions::AGENCY => 0.2,
        ScoreDimensions::TIMELINESS => 0.2,
    ], 0.3, 'news_media');

    mock(LlmScorer::class)
        ->shouldNotReceive('score');

    $job = new ScoreArticleWithLlm($article->id);

    $job->handle(app(AnalysisGate::class), app(LlmScorer::class), app(CivicRelevanceCalculator::class));

    $analysis = ArticleAnalysis::first();

    expect($analysis?->status)->toBe('heuristics_done')
        ->and($analysis?->llm_scores)->toBeNull();
});

it('stores llm scores when the gate allows scoring', function () {
    config()->set('analysis.llm.enabled', true);
    config()->set('analysis.llm.min_cleaned_text_chars', 1);

    $article = makeArticleWithAnalysis([
        ScoreDimensions::AGENCY => 0.8,
        ScoreDimensions::TIMELINESS => 0.6,
    ], 0.7, 'news_media');

    mock(LlmScorer::class)
        ->shouldReceive('score')
        ->once()
        ->andReturn([
            'dimensions' => [
                ScoreDimensions::COMPREHENSIBILITY => 0.7,
                ScoreDimensions::ORIENTATION => 0.6,
                ScoreDimensions::REPRESENTATION => 0.5,
                ScoreDimensions::AGENCY => 0.8,
                ScoreDimensions::RELEVANCE => 0.4,
                ScoreDimensions::TIMELINESS => 0.9,
            ],
            'justifications' => [
                ScoreDimensions::COMPREHENSIBILITY => 'Clear writing.',
                ScoreDimensions::AGENCY => 'Includes participation steps.',
            ],
            'opportunities' => [
                [
                    'kind' => 'meeting',
                    'title' => 'Public meeting',
                    'starts_at' => now()->addDays(10),
                    'url' => 'https://example.com/meeting',
                    'confidence' => 0.8,
                ],
            ],
            'confidence' => 0.82,
            'model' => 'test-model',
        ]);

    $job = new ScoreArticleWithLlm($article->id);

    $job->handle(app(AnalysisGate::class), app(LlmScorer::class), app(CivicRelevanceCalculator::class));

    $analysis = ArticleAnalysis::first();

    expect($analysis?->status)->toBe('llm_done')
        ->and($analysis?->llm_scores)->not->toBeNull()
        ->and($analysis?->model)->toBe('test-model');
});
