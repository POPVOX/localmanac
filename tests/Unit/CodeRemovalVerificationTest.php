<?php

use App\Services\Chat\AnswerSynthesizer;
use Tests\TestCase;

/**
 * Code removal verification tests.
 *
 * Validates: Requirements 2.1, 2.2, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 7.1, 7.2
 */
uses(TestCase::class);

// ── AskService: removed dependencies (Requirements 2.1, 2.2, 7.1, 7.2) ──

it('AskService source file does not reference ChatEvidenceModeClassifier', function () {
    $source = file_get_contents(app_path('Services/Chat/AskService.php'));

    expect(str_contains($source, 'ChatEvidenceModeClassifier'))->toBeFalse();
});

it('AskService source file does not reference ChatUpdatesAnswerService', function () {
    $source = file_get_contents(app_path('Services/Chat/AskService.php'));

    expect(str_contains($source, 'ChatUpdatesAnswerService'))->toBeFalse();
});

// ── AnswerSynthesizer: removed fallback methods (Requirements 5.2, 5.3, 5.4, 5.5) ──

it('AnswerSynthesizer no longer has answerFromSeedEvidence method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('answerFromSeedEvidence'))->toBeFalse();
});

it('AnswerSynthesizer no longer has shouldUseFilteredLocalEventsInFinalAnswer method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('shouldUseFilteredLocalEventsInFinalAnswer'))->toBeFalse();
});

it('AnswerSynthesizer no longer has shouldConstrainProceduralAnswer method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('shouldConstrainProceduralAnswer'))->toBeFalse();
});

it('AnswerSynthesizer no longer has narrowProceduralAnswerFromEvidence method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('narrowProceduralAnswerFromEvidence'))->toBeFalse();
});

it('AnswerSynthesizer no longer has shouldRejectProceduralEventAnswer method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('shouldRejectProceduralEventAnswer'))->toBeFalse();
});

it('AnswerSynthesizer no longer has shouldRejectProceduralAnswerForNonProceduralQuery method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('shouldRejectProceduralAnswerForNonProceduralQuery'))->toBeFalse();
});

// ── AnswerSynthesizer: removed web search (Requirements 6.1, 6.2) ──

it('AnswerSynthesizer no longer has isWebSearchEnabledForQuestion method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('isWebSearchEnabledForQuestion'))->toBeFalse();
});

it('AnswerSynthesizer no longer has chatModelForQuestion method', function () {
    $ref = new ReflectionClass(AnswerSynthesizer::class);

    expect($ref->hasMethod('chatModelForQuestion'))->toBeFalse();
});

// ── AnswerSynthesizer: buildTools never returns WebSearch (Requirement 6.1) ──

it('AnswerSynthesizer buildTools never returns a WebSearch instance', function () {
    $city = new \App\Models\City;
    $city->name = 'Wichita';

    $source = new \App\Models\ChatSource;
    $source->id = 1;
    $source->source_url = 'https://example.com';

    config()->set('chat.tools.similarity.enabled', true);

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = $method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        'What are the latest updates?',
    );

    foreach ($tools as $tool) {
        expect($tool)->not->toBeInstanceOf(\Laravel\Ai\Providers\Tools\WebSearch::class);
    }
});

// ── AnswerSynthesizer: source file has no WebSearch import (Requirement 6.2) ──

it('AnswerSynthesizer source file does not import WebSearch', function () {
    $source = file_get_contents(app_path('Services/Chat/AnswerSynthesizer.php'));

    expect(str_contains($source, 'use Laravel\Ai\Providers\Tools\WebSearch'))->toBeFalse();
});
