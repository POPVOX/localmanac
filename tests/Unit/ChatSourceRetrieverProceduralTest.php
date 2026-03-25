<?php

use App\Services\Chat\ChatSourceRetriever;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Helper to invoke a private method on ChatSourceRetriever.
 */
function invokeRetrieverMethod(ChatSourceRetriever $retriever, string $method, array $args): mixed
{
    $ref = new ReflectionMethod($retriever, $method);

    return $ref->invoke($retriever, ...$args);
}

function retriever(): ChatSourceRetriever
{
    return app(ChatSourceRetriever::class);
}

it('isProceduralQuestion returns true for how-to questions', function (string $question) {
    $result = invokeRetrieverMethod(retriever(), 'isProceduralQuestion', [$question]);

    expect($result)->toBeTrue();
})->with([
    'How do I apply for a building permit?',
    'How to renew my business license?',
    'What do I need to get a garage sale permit?',
    'Where do I apply for a dog license?',
    'What are the steps to file a complaint?',
    'What is the process for rezoning?',
]);

it('isProceduralQuestion returns true for action questions with personal pronouns', function (string $question) {
    $result = invokeRetrieverMethod(retriever(), 'isProceduralQuestion', [$question]);

    expect($result)->toBeTrue();
})->with([
    'I need to apply for a permit',
    'Where can I submit my application?',
    'What documents do I need for a license?',
    'How much does a permit cost?',
]);

it('isProceduralQuestion returns false for non-procedural questions', function (string $question) {
    $result = invokeRetrieverMethod(retriever(), 'isProceduralQuestion', [$question]);

    expect($result)->toBeFalse();
})->with([
    'What are the city council meeting hours?',
    'Who is the mayor?',
    'What is the population of the city?',
    '',
]);

it('isProceduralQuestion returns false for aggregation/updates questions', function (string $question) {
    $result = invokeRetrieverMethod(retriever(), 'isProceduralQuestion', [$question]);

    expect($result)->toBeFalse();
})->with([
    'What new permits have been filed?',
    'What are the recent updates on projects?',
    'Are there any active service alerts?',
]);

it('isProceduralQuestion returns false for event-intent questions', function () {
    $result = invokeRetrieverMethod(retriever(), 'isProceduralQuestion', ['What events are happening this weekend?']);

    expect($result)->toBeFalse();
});

it('proceduralFocusTerms returns domain-specific terms excluding generic words', function () {
    $terms = invokeRetrieverMethod(retriever(), 'proceduralFocusTerms', ['How do I apply for a building permit?']);

    expect($terms)
        ->toBeArray()
        ->toContain('building')
        ->not->toContain('how')
        ->not->toContain('apply')
        ->not->toContain('permit');
});

it('proceduralFocusTerms returns empty for generic procedural question', function () {
    $terms = invokeRetrieverMethod(retriever(), 'proceduralFocusTerms', ['How do I apply?']);

    expect($terms)->toBeEmpty();
});

it('proceduralFocusTerms extracts multiple domain terms', function () {
    $terms = invokeRetrieverMethod(retriever(), 'proceduralFocusTerms', ['How do I apply for a residential fence permit?']);

    expect($terms)
        ->toContain('residential')
        ->toContain('fence');
});

it('proceduralSignals returns the canonical list of signal phrases', function () {
    $signals = invokeRetrieverMethod(retriever(), 'proceduralSignals', []);

    expect($signals)
        ->toBeArray()
        ->not->toBeEmpty()
        ->toContain('apply')
        ->toContain('application')
        ->toContain('submit')
        ->toContain('approval')
        ->toContain('inspection')
        ->toContain('required')
        ->toContain('fee')
        ->toContain('fees')
        ->toContain('certificate')
        ->toContain('document')
        ->toContain('documents');
});
