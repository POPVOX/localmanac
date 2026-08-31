<?php

use App\Services\Chat\Evaluation\RetrievalMetrics;
use Tests\TestCase;

uses(TestCase::class);

it('calculates ranked retrieval metrics and requirement groups', function () {
    $result = app(RetrievalMetrics::class)->evaluate(
        retrieved: ['https://example.test/noise', 'https://example.test/required/', 'https://example.test/choice'],
        required: ['https://example.test/required'],
        anyOf: ['https://example.test/choice', 'https://example.test/alternative'],
        excluded: ['https://example.test/forbidden'],
        k: 3,
    );

    expect($result['pass'])->toBeTrue()
        ->and($result['recall_at_k'])->toBe(1.0)
        ->and($result['precision_at_k'])->toBe(2 / 3)
        ->and($result['reciprocal_rank'])->toBe(0.5)
        ->and($result['excluded_hits'])->toBe(0);
});

it('scores expected no-source cases explicitly', function () {
    $empty = app(RetrievalMetrics::class)->evaluate([], expectNoSource: true);
    $unexpected = app(RetrievalMetrics::class)->evaluate(['https://example.test/source'], expectNoSource: true);

    expect($empty['pass'])->toBeTrue()
        ->and($empty['no_source_correct'])->toBeTrue()
        ->and($unexpected['pass'])->toBeFalse()
        ->and($unexpected['no_source_correct'])->toBeFalse();
});
