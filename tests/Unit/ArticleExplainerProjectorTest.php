<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Services\Analysis\ArticleExplainerProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('projects explainer payload into projections', function () {
    $article = Article::factory()->create();

    $payload = [
        'explainer' => [
            'whats_happening' => "  New plan \n for downtown. ",
            'why_it_matters' => '  It affects residents.  ',
            'key_details' => [
                ' First detail ',
                ['label' => 'Budget', 'value' => ' $2M '],
                ['text' => ' Extra detail '],
            ],
            'what_to_watch' => [
                ' Next meeting May 5 ',
                'Second item',
                'Third item',
                'Fourth item',
                'Fifth item',
                'Sixth item',
            ],
            'evidence' => [
                'whats_happening' => [
                    ['quote' => 'City Council voted.', 'start' => 12.2, 'end' => 34.8],
                    ['quote' => '  '],
                ],
                'why_it_matters' => [
                    ['quote' => 'Residents could see changes.', 'start' => '40', 'end' => '65'],
                ],
            ],
        ],
    ];

    app(ArticleExplainerProjector::class)->projectForArticle($article, $payload);

    $explainer = ArticleExplainer::query()
        ->where('article_id', $article->id)
        ->first();

    expect($explainer)->not->toBeNull()
        ->and($explainer?->whats_happening)->toBe('New plan for downtown.')
        ->and($explainer?->why_it_matters)->toBe('It affects residents.');

    expect($explainer?->key_details)->toBe([
        'First detail',
        ['label' => 'Budget', 'value' => '$2M'],
        'Extra detail',
    ]);

    expect($explainer?->what_to_watch)->toHaveCount(5)
        ->and($explainer?->what_to_watch[0])->toBe('Next meeting May 5')
        ->and($explainer?->what_to_watch[4])->toBe('Fifth item');

    expect($explainer?->evidence_json)->toBe([
        'whats_happening' => [
            ['quote' => 'City Council voted.', 'start' => 12, 'end' => 35],
        ],
        'why_it_matters' => [
            ['quote' => 'Residents could see changes.', 'start' => 40, 'end' => 65],
        ],
    ]);
});

it('replaces generic meeting explainer text before saving the projection', function () {
    $article = Article::factory()->create([
        'title' => 'March 10 City Council Meeting recap',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => implode("\n\n", [
            'Yesterday at the City Council meeting, the Council heard the following items:',
            '* Consent Agenda with the exception of item 6 – Approved 7/0',
            '* Board of Bids and Contracts – Approved 7/0',
            '* Petitions for Public Improvements – Approved 7/0',
        ]),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $payload = [
        'explainer' => [
            'whats_happening' => 'During the March 10 City Council meeting, various items were discussed. The Council focused on important local issues affecting Wichita residents.',
            'why_it_matters' => 'Understanding the outcomes of these meetings helps residents stay informed about community decisions and local governance.',
            'key_details' => null,
            'what_to_watch' => null,
            'evidence' => null,
        ],
    ];

    app(ArticleExplainerProjector::class)->projectForArticle($article->fresh(), $payload);

    $explainer = ArticleExplainer::query()
        ->where('article_id', $article->id)
        ->first();

    expect($explainer)->not->toBeNull()
        ->and($explainer?->whats_happening)->toContain('Consent Agenda with the exception of item 6')
        ->and($explainer?->whats_happening)->toContain('Board of Bids and Contracts')
        ->and($explainer?->why_it_matters)->toBeNull();
});
