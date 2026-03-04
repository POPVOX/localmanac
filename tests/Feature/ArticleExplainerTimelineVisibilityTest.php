<?php

use App\Models\Article;
use App\Models\ProcessTimelineItem;

test('hides process timeline when no items exist', function () {
    $article = Article::factory()->create();

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertDontSeeText('Where we are in the process');
});

test('hides process timeline when items are low signal', function () {
    $article = Article::factory()->create();

    ProcessTimelineItem::query()->create([
        'article_id' => $article->id,
        'city_id' => $article->city_id,
        'key' => 'context_only',
        'label' => 'Context Mentioned',
        'status' => 'upcoming',
        'date' => null,
        'has_time' => false,
        'badge_text' => null,
        'note' => '   ',
        'source' => 'analysis_llm',
        'position' => 1,
    ]);

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertDontSeeText('Where we are in the process');
});

test('shows process timeline when any item has date or note', function () {
    $article = Article::factory()->create();

    ProcessTimelineItem::query()->create([
        'article_id' => $article->id,
        'city_id' => $article->city_id,
        'key' => 'next_hearing',
        'label' => 'Next Hearing',
        'status' => 'current',
        'date' => null,
        'has_time' => false,
        'badge_text' => null,
        'note' => 'Board hearing expected soon.',
        'source' => 'analysis_llm',
        'position' => 1,
    ]);

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertSeeText('Where we are in the process')
        ->assertSeeText('Next Hearing');
});
