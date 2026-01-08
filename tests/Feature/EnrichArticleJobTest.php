<?php

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleBody;
use App\Models\ArticleEntity;
use App\Models\ArticleIssueArea;
use App\Models\ArticleKeyword;
use App\Models\City;
use App\Models\Claim;
use App\Models\IssueArea;
use App\Services\Analysis\ArticleExplainerProjector;
use App\Services\Analysis\CivicActionProjector;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\ProcessTimelineProjector;
use App\Services\Analysis\ScoreDimensions;
use App\Services\Extraction\ClaimWriter;
use App\Services\Extraction\Enricher;
use App\Services\Extraction\ProjectionWriter;

it('writes claims and projections when enrichment job runs', function () {
    config()->set('enrichment.enabled', true);
    config()->set('enrichment.model', 'test-model');
    config()->set('enrichment.prompt_version', 'test-prompt');

    $city = City::create([
        'name' => 'Enrich City',
        'slug' => 'enrich-city',
    ]);

    $issueArea = IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Budget',
        'slug' => 'budget',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Enrich Article',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('City budget meeting. ', 80),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $payload = [
        'analysis' => [
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
            'opportunities' => [],
            'confidence' => 0.81,
        ],
        'enrichment' => [
            'people' => [
                [
                    'name' => 'Jordan Lee',
                    'role' => 'Treasurer',
                    'confidence' => 0.82,
                    'evidence' => [
                        ['quote' => 'Treasurer Jordan Lee presented the budget.'],
                    ],
                ],
            ],
            'organizations' => [],
            'locations' => [],
            'keywords' => [
                [
                    'keyword' => 'budget',
                    'confidence' => 0.76,
                    'evidence' => [
                        ['quote' => 'The budget will be voted on next week.'],
                    ],
                ],
            ],
            'issue_areas' => [
                [
                    'slug' => $issueArea->slug,
                    'confidence' => 0.7,
                    'evidence' => [
                        ['quote' => 'Budget priorities were discussed.'],
                    ],
                ],
            ],
        ],
        'process_timeline' => [
            'items' => [],
            'current_key' => null,
        ],
        'confidence' => 0.8,
    ];

    $this->instance(Enricher::class, new class($payload) extends Enricher
    {
        public function __construct(private array $payload) {}

        public function enrich(Article $article): array
        {
            return $this->payload;
        }
    });

    $job = new EnrichArticle($article->id);
    $job->handle(
        app(Enricher::class),
        app(ClaimWriter::class),
        app(ProjectionWriter::class),
        app(CivicActionProjector::class),
        app(ProcessTimelineProjector::class),
        app(ArticleExplainerProjector::class),
        app(CivicRelevanceCalculator::class)
    );

    expect(Claim::count())->toBe(3)
        ->and(ArticleKeyword::count())->toBe(1)
        ->and(ArticleEntity::count())->toBe(1)
        ->and(ArticleIssueArea::count())->toBe(1);

    $claim = Claim::first();
    expect($claim?->model)->toBe('test-model')
        ->and($claim?->prompt_version)->toBe('test-prompt');

    $analysis = ArticleAnalysis::first();
    $expectedScore = app(CivicRelevanceCalculator::class)->compute($payload['analysis']['dimensions']);
    $analysisPayload = array_merge($payload['analysis'], [
        'process_timeline' => $payload['process_timeline'],
        'explainer' => null,
    ]);

    expect($analysis)->not->toBeNull()
        ->and($analysis?->status)->toBe('llm_done')
        ->and($analysis?->llm_scores)->toBe($analysisPayload);

    expect((float) $analysis?->civic_relevance_score)
        ->toBeGreaterThanOrEqual($expectedScore - 0.001)
        ->toBeLessThanOrEqual($expectedScore + 0.001);
});
