<?php

use App\Services\Chat\ChatSourceRetriever;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Helper to invoke a private method on ChatSourceRetriever.
 */
function invokePrivateMethod(ChatSourceRetriever $retriever, string $method, array $args): mixed
{
    $ref = new ReflectionMethod($retriever, $method);

    return $ref->invoke($retriever, ...$args);
}

function makeRetriever(): ChatSourceRetriever
{
    return app(ChatSourceRetriever::class);
}

function makeArticleRow(array $overrides = []): object
{
    return (object) array_merge([
        'id' => 42,
        'title' => 'City Council Approves New Park',
        'summary' => 'The city council voted to approve a new park downtown.',
        'cleaned_text' => 'Full body text of the article about the new park project.',
        'whats_happening' => '',
        'why_it_matters' => '',
        'source_url' => 'https://example.com/articles/new-park',
        'rank' => 0.75,
    ], $overrides);
}

it('mapArticleEvidence normalizes an article row into Evidence_Item format', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow();

    $result = invokePrivateMethod($retriever, 'mapArticleEvidence', [$row]);

    expect($result)
        ->toBeArray()
        ->toHaveKeys(['id', 'title', 'source_url', 'type', 'snippet', 'score'])
        ->and($result['id'])->toBe('article_42')
        ->and($result['title'])->toBe('City Council Approves New Park')
        ->and($result['source_url'])->toBe('https://example.com/articles/new-park')
        ->and($result['type'])->toBe('html')
        ->and($result['snippet'])->not->toBeEmpty()
        ->and($result['score'])->toBeGreaterThanOrEqual(1);
});

it('mapArticleEvidence uses source_url from article_sources', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow(['source_url' => 'https://news.example.com/story/123']);

    $result = invokePrivateMethod($retriever, 'mapArticleEvidence', [$row]);

    expect($result['source_url'])->toBe('https://news.example.com/story/123');
});

it('mapArticleEvidence sets id with article_ prefix', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow(['id' => 99]);

    $result = invokePrivateMethod($retriever, 'mapArticleEvidence', [$row]);

    expect($result['id'])->toBe('article_99');
});

it('mapArticleEvidence defaults title to Article when missing', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow(['title' => null]);

    $result = invokePrivateMethod($retriever, 'mapArticleEvidence', [$row]);

    expect($result['title'])->toBe('Article');
});

it('articleSnippet prefers explainer text over summary', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow([
        'whats_happening' => 'The council approved a new park.',
        'why_it_matters' => 'This will increase green space downtown.',
        'summary' => 'Summary text that should not be used.',
    ]);

    $result = invokePrivateMethod($retriever, 'articleSnippet', [$row]);

    expect($result)
        ->toContain('The council approved a new park.')
        ->toContain('This will increase green space downtown.')
        ->not->toContain('Summary text that should not be used.');
});

it('articleSnippet uses summary when no explainer exists', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow([
        'whats_happening' => '',
        'why_it_matters' => '',
        'summary' => 'The city council voted to approve a new park downtown.',
    ]);

    $result = invokePrivateMethod($retriever, 'articleSnippet', [$row]);

    expect($result)->toBe('The city council voted to approve a new park downtown.');
});

it('articleSnippet falls back to cleaned_text when no explainer or summary', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow([
        'whats_happening' => '',
        'why_it_matters' => '',
        'summary' => '',
        'cleaned_text' => 'Full body text of the article.',
    ]);

    $result = invokePrivateMethod($retriever, 'articleSnippet', [$row]);

    expect($result)->toBe('Full body text of the article.');
});

it('articleSnippet truncates cleaned_text to chunk_max_chars config', function () {
    config()->set('chat.chunk_max_chars', 20);

    $retriever = makeRetriever();
    $row = makeArticleRow([
        'whats_happening' => '',
        'why_it_matters' => '',
        'summary' => '',
        'cleaned_text' => str_repeat('A', 100),
    ]);

    $result = invokePrivateMethod($retriever, 'articleSnippet', [$row]);

    expect(mb_strlen($result))->toBe(20);
});

it('articleSnippet uses only whats_happening when why_it_matters is empty', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow([
        'whats_happening' => 'Something is happening.',
        'why_it_matters' => '',
    ]);

    $result = invokePrivateMethod($retriever, 'articleSnippet', [$row]);

    expect($result)->toBe('Something is happening.');
});

it('articleSnippet uses only why_it_matters when whats_happening is empty', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow([
        'whats_happening' => '',
        'why_it_matters' => 'This matters because of reasons.',
    ]);

    $result = invokePrivateMethod($retriever, 'articleSnippet', [$row]);

    expect($result)->toBe('This matters because of reasons.');
});

it('articleSnippet returns empty string when all fields are empty', function () {
    $retriever = makeRetriever();
    $row = makeArticleRow([
        'whats_happening' => '',
        'why_it_matters' => '',
        'summary' => '',
        'cleaned_text' => '',
    ]);

    $result = invokePrivateMethod($retriever, 'articleSnippet', [$row]);

    expect($result)->toBe('');
});
