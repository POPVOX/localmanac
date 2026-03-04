<?php

use App\Models\Article;

test('article explainer page renders participation section', function () {
    $article = Article::factory()->create();

    $response = $this->get(route('articles.show', $article));

    $response
        ->assertOk()
        ->assertSeeText('How to Participate')
        ->assertSeeText('No people or organizations listed yet.')
        ->assertDontSeeText('No extracted entities yet.');
});
