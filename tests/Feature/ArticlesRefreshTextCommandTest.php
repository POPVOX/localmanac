<?php

use App\Models\Article;
use App\Models\ArticleExplainer;
use App\Models\City;

it('refreshes titles and summaries via the command', function () {
    $city = City::create([
        'name' => 'Refresh City',
        'slug' => 'refresh-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '1234_LegalNotice (PDF)',
        'summary' => 'Existing summary...',
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'whats_happening' => 'The city will hold a public hearing on the zoning change next week.',
        'why_it_matters' => 'Residents can submit feedback.',
    ]);

    $this->artisan('articles:refresh-text --limit=1')->assertExitCode(0);

    $article->refresh();

    expect($article->summary)
        ->toBe('The city will hold a public hearing on the zoning change next week.')
        ->and($article->title)
        ->toBe('City will hold a public hearing on the zoning change next week');
});
