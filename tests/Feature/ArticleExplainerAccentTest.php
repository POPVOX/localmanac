<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;

test('article explainer page renders participation section', function () {
    $article = Article::factory()->create();

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertSeeText('AI summaries can make mistakes.')
        ->assertSeeText('You may want to check them before acting on information you read here.')
        ->assertSeeText('Source Article')
        ->assertSeeText('Read full source')
        ->assertDontSeeText('How to Participate')
        ->assertSeeText('No people or organizations listed yet.')
        ->assertDontSeeText('No extracted entities yet.');
});

test('article explainer page replaces weak meeting boilerplate with a fallback summary', function () {
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

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $article->city_id,
        'whats_happening' => 'During the March 10 City Council meeting, various items were discussed. The Council focused on important local issues affecting Wichita residents.',
        'why_it_matters' => 'Understanding the outcomes of these meetings helps residents stay informed about community decisions and local governance.',
    ]);

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertSeeText('Consent Agenda with the exception of item 6')
        ->assertSeeText('Board of Bids and Contracts')
        ->assertDontSeeText('various items were discussed')
        ->assertDontSeeText('community decisions and local governance');
});

test('article explainer page returns not found for non-numeric article identifiers', function () {
    $this->get('/articles/41E964A4-B928-4C38-98C0-CC80E62EC089')
        ->assertNotFound();
});

test('article source page returns not found for non-numeric article identifiers', function () {
    $this->get('/articles/41E964A4-B928-4C38-98C0-CC80E62EC089/source')
        ->assertNotFound();
});
