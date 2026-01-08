<?php

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\City;
use App\Models\CivicAction;
use App\Services\Analysis\CivicActionProjector;
use Illuminate\Support\Carbon;

test('it projects civic actions from llm opportunities', function () {
    $this->travelTo(Carbon::parse('2024-12-01 00:00:00'));

    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
    ]);

    ArticleAnalysis::factory()->create([
        'article_id' => $article->id,
        'final_scores' => [
            'opportunities' => [
                [
                    'type' => 'meeting',
                    'date' => '2025-01-22',
                    'time' => '6:00 PM',
                    'location' => 'City Hall, 455 N. Main, Wichita, Kansas 67202',
                    'url' => 'https://example.com/meeting',
                    'description' => 'Public hearing regarding bond issuance.',
                    'evidence' => [
                        ['quote' => 'Public hearing will be held at City Hall.'],
                    ],
                ],
                [
                    'type' => 'public_comment',
                    'date' => '2025-01-15',
                    'location' => null,
                    'url' => 'https://example.com/comment',
                    'description' => 'Comment period for the plan.',
                    'evidence' => [
                        ['quote' => 'Written comments are accepted through Jan 15.'],
                    ],
                ],
            ],
        ],
        'llm_scores' => [],
    ]);

    app(CivicActionProjector::class)->projectForArticle($article->fresh());

    $actions = CivicAction::query()
        ->where('article_id', $article->id)
        ->orderBy('position')
        ->get();

    expect($actions)->toHaveCount(2);

    $comment = $actions->firstWhere('kind', 'comment');
    $hearing = $actions->firstWhere('kind', 'hearing');

    expect($comment)->not->toBeNull()
        ->and($comment->title)->toBe('Submit a Comment')
        ->and($comment->cta_label)->toStartWith('Submit online')
        ->and($comment->badge_text)->toBe('Closes Jan 15');

    expect($hearing)->not->toBeNull()
        ->and($hearing->title)->toBe('Attend the Hearing')
        ->and($hearing->subtitle)->toBeNull()
        ->and($hearing->location)->toBe('City Hall, 455 N. Main')
        ->and($hearing->badge_text)->toBe('Jan 22, 6:00 PM');

    expect($actions->pluck('title')->implode(' '))
        ->not->toContain('City Council Meeting');

    $this->travelBack();
});
