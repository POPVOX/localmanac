<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\City;
use App\Models\IssueArea;
use App\Services\Extraction\Agents\CivicAnalysisAgent;
use App\Services\Extraction\Agents\EntityEnrichmentAgent;
use App\Services\Extraction\Agents\ExplainerAgent;
use App\Services\Extraction\Enricher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns civic analysis and timeline when enrichment call fails', function () {
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Test City',
        'slug' => 'test-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Public Hearing Notice',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('City Council meeting scheduled. ', 50),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $civicStructured = [
        'analysis' => [
            'dimensions' => [
                'comprehensibility' => 0.6,
                'orientation' => 0.5,
                'representation' => 0.4,
                'agency' => 0.7,
                'relevance' => 0.8,
                'timeliness' => 0.9,
            ],
            'justifications' => [
                'comprehensibility' => 'Clear notice.',
                'orientation' => 'Explains next steps.',
                'representation' => 'Mentions city staff.',
                'agency' => 'Invites participation.',
                'relevance' => 'Civic process.',
                'timeliness' => 'Upcoming date.',
            ],
            'opportunities' => [
                [
                    'type' => 'meeting',
                    'date' => '2030-02-01',
                    'time' => '18:00',
                    'location' => 'City Hall',
                    'url' => 'https://example.com/meeting',
                    'description' => 'Public meeting.',
                    'evidence' => [
                        ['quote' => 'Public meeting on Feb 1.', 'start' => 10, 'end' => 34],
                    ],
                ],
            ],
            'confidence' => 0.74,
        ],
        'process_timeline' => [
            'items' => [
                [
                    'key' => 'public_comment',
                    'label' => 'Public comment',
                    'date' => '2030-01-20',
                    'status' => 'current',
                    'badge_text' => 'OPEN NOW',
                    'note' => null,
                    'evidence' => [
                        ['quote' => 'Comment period is open.', 'start' => 40, 'end' => 66],
                    ],
                ],
            ],
            'current_key' => 'public_comment',
        ],
        'confidence' => 0.82,
    ];

    CivicAnalysisAgent::fake([
        $civicStructured,
    ]);
    EntityEnrichmentAgent::fake([
        fn (): never => throw new RuntimeException('Entity enrichment call failed.'),
    ]);
    ExplainerAgent::fake([
        fn (): never => throw new RuntimeException('Explainer call failed.'),
    ]);

    $payload = app(Enricher::class)->enrich($article->fresh());

    expect($payload['analysis']['dimensions']['agency'])->toBe(0.7)
        ->and($payload['process_timeline']['current_key'])->toBe('public_comment')
        ->and($payload['enrichment']['people'])->toBe([])
        ->and($payload['enrichment']['issue_areas'])->toBe([])
        ->and($payload['confidence'])->toBe(0.82);
});

it('merges enrichment results when the entity call succeeds', function () {
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Test City',
        'slug' => 'test-city',
    ]);

    $issueArea = IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Budget',
        'slug' => 'budget',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Budget Update',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('Budget hearing notice and agenda. ', 60),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $civicStructured = [
        'analysis' => [
            'dimensions' => [
                'comprehensibility' => 0.5,
                'orientation' => 0.4,
                'representation' => 0.3,
                'agency' => 0.6,
                'relevance' => 0.7,
                'timeliness' => 0.8,
            ],
            'justifications' => [
                'comprehensibility' => 'Clear writing.',
                'orientation' => 'Outlines process.',
                'representation' => 'Mentions departments.',
                'agency' => 'Includes comment period.',
                'relevance' => 'City budget.',
                'timeliness' => 'Upcoming date.',
            ],
            'opportunities' => [],
            'confidence' => 0.61,
        ],
        'process_timeline' => [
            'items' => [],
            'current_key' => null,
        ],
        'confidence' => 0.88,
    ];

    $enrichmentStructured = [
        'enrichment' => [
            'people' => [
                [
                    'name' => 'Jordan Lee',
                    'role' => 'Treasurer',
                    'confidence' => 0.8,
                    'evidence' => [
                        ['quote' => 'Treasurer Jordan Lee', 'start' => 5, 'end' => 25],
                    ],
                ],
            ],
            'organizations' => [
                [
                    'name' => 'City Council',
                    'type_guess' => 'government',
                    'confidence' => 0.77,
                    'evidence' => [
                        ['quote' => 'City Council', 'start' => 0, 'end' => 11],
                    ],
                ],
            ],
            'locations' => [],
            'keywords' => [
                [
                    'keyword' => 'budget',
                    'confidence' => 0.7,
                    'evidence' => [
                        ['quote' => 'budget', 'start' => 100, 'end' => 106],
                    ],
                ],
            ],
            'issue_areas' => [
                [
                    'slug' => $issueArea->slug,
                    'confidence' => 0.65,
                    'evidence' => [
                        ['quote' => 'budget', 'start' => 100, 'end' => 106],
                    ],
                ],
            ],
            'confidence' => 0.69,
        ],
        'confidence' => 0.58,
    ];

    CivicAnalysisAgent::fake([
        $civicStructured,
    ]);
    EntityEnrichmentAgent::fake([
        $enrichmentStructured,
    ]);
    ExplainerAgent::fake([
        ['explainer' => []],
    ]);

    $payload = app(Enricher::class)->enrich($article->fresh());

    expect($payload['analysis']['dimensions']['relevance'])->toBe(0.7)
        ->and($payload['enrichment']['people'][0]['name'])->toBe('Jordan Lee')
        ->and($payload['enrichment']['issue_areas'][0]['slug'])->toBe('budget')
        ->and($payload['enrichment']['confidence'])->toBe(0.69)
        ->and($payload['confidence'])->toBe(0.88);
});

it('enriches short cleaned text instead of returning an empty payload', function () {
    config()->set('enrichment.enabled', true);
    config()->set('enrichment.min_cleaned_text_chars', 800);

    $city = City::create([
        'name' => 'Short Text City',
        'slug' => 'short-text-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Short civic update',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'Bond election vote is Tuesday.',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    CivicAnalysisAgent::fake([
        [
            'analysis' => [
                'dimensions' => [
                    'comprehensibility' => 0.5,
                    'orientation' => 0.4,
                    'representation' => 0.3,
                    'agency' => 0.6,
                    'relevance' => 0.7,
                    'timeliness' => 0.8,
                ],
                'justifications' => [
                    'comprehensibility' => 'Short but clear.',
                    'orientation' => 'Explains what to watch.',
                    'representation' => 'Mentions voters.',
                    'agency' => 'Contains civic action.',
                    'relevance' => 'Local election context.',
                    'timeliness' => 'Upcoming date.',
                ],
                'opportunities' => [],
                'confidence' => 0.76,
            ],
            'process_timeline' => [
                'items' => [],
                'current_key' => null,
            ],
            'confidence' => 0.76,
        ],
    ]);
    EntityEnrichmentAgent::fake([
        [
            'enrichment' => [
                'people' => [],
                'organizations' => [],
                'locations' => [],
                'keywords' => [
                    [
                        'keyword' => 'bond election',
                        'confidence' => 0.7,
                        'evidence' => [
                            ['quote' => 'Bond election vote is Tuesday.', 'start' => 0, 'end' => 30],
                        ],
                    ],
                ],
                'issue_areas' => [],
                'confidence' => 0.7,
            ],
            'confidence' => 0.7,
        ],
    ]);
    ExplainerAgent::fake([
        [
            'explainer' => [
                'whats_happening' => 'Voters will decide on a bond election.',
                'why_it_matters' => 'It affects school funding.',
                'key_details' => ['Election on Tuesday'],
                'what_to_watch' => ['Final vote totals'],
                'evidence' => null,
            ],
        ],
    ]);

    $payload = app(Enricher::class)->enrich($article->fresh());

    expect($payload['analysis']['confidence'])->toBe(0.76)
        ->and($payload['enrichment']['keywords'])->toHaveCount(1)
        ->and($payload['explainer']['whats_happening'])->toBe('Voters will decide on a bond election.');
});

it('uses title and summary as fallback text when cleaned text is empty', function () {
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Fallback City',
        'slug' => 'fallback-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'City updates park plan',
        'summary' => 'Council approved a revised parks maintenance timeline.',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => '',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    CivicAnalysisAgent::fake([
        [
            'analysis' => [
                'dimensions' => [
                    'comprehensibility' => 0.5,
                    'orientation' => 0.5,
                    'representation' => 0.4,
                    'agency' => 0.6,
                    'relevance' => 0.7,
                    'timeliness' => 0.7,
                ],
                'justifications' => [
                    'comprehensibility' => 'Clear summary.',
                    'orientation' => 'Includes what changed.',
                    'representation' => 'References council decision.',
                    'agency' => 'Residents can track updates.',
                    'relevance' => 'Local civic impact.',
                    'timeliness' => 'Recent action.',
                ],
                'opportunities' => [],
                'confidence' => 0.71,
            ],
            'process_timeline' => [
                'items' => [],
                'current_key' => null,
            ],
            'confidence' => 0.71,
        ],
    ]);
    EntityEnrichmentAgent::fake([
        [
            'enrichment' => [
                'people' => [],
                'organizations' => [],
                'locations' => [],
                'keywords' => [],
                'issue_areas' => [],
                'confidence' => 0.6,
            ],
            'confidence' => 0.6,
        ],
    ]);
    ExplainerAgent::fake([
        [
            'explainer' => [
                'whats_happening' => 'The city approved an updated plan.',
                'why_it_matters' => 'Residents will see scheduling changes.',
                'key_details' => null,
                'what_to_watch' => null,
                'evidence' => null,
            ],
        ],
    ]);

    $payload = app(Enricher::class)->enrich($article->fresh());

    expect($payload['analysis']['confidence'])->toBe(0.71)
        ->and($payload['explainer']['why_it_matters'])->toBe('Residents will see scheduling changes.');
});
