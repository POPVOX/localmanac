<?php

use App\Models\Article;
use App\Models\ArticleOpportunity;
use Illuminate\Support\Carbon;

test('article explainer hides participation card when article has no participation opportunities', function () {
    $article = Article::factory()->create([
        'title' => 'Ordinary news article',
        'canonical_url' => 'https://example.com/news/story',
    ]);

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertDontSeeText('How to Participate')
        ->assertSeeText('Source Article')
        ->assertSeeText('Read full source')
        ->assertDontSeeText('Read the Proposal')
        ->assertDontSeeText('Full application and supporting documents')
        ->assertDontSeeText('View documents');
});

test('article explainer shows participation card when article has a real participation opportunity', function () {
    $article = Article::factory()->create([
        'title' => 'Planning commission agenda',
        'canonical_url' => 'https://example.com/news/hearing',
    ]);

    ArticleOpportunity::factory()->create([
        'article_id' => $article->id,
        'kind' => 'meeting',
        'title' => 'Planning Commission Hearing',
        'starts_at' => Carbon::parse('2026-04-10 18:00:00'),
        'ends_at' => Carbon::parse('2026-04-10 20:00:00'),
        'location' => 'City Hall',
        'url' => 'https://example.com/hearing-details',
    ]);

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertSeeText('How to Participate')
        ->assertSeeText('Attend the Hearing')
        ->assertSeeText('Planning Commission Hearing')
        ->assertSeeText('Add to calendar')
        ->assertSeeText('Source Article')
        ->assertSeeText('Read full source');
});
