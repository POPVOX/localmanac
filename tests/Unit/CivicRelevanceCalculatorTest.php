<?php

use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\ScoreDimensions;

it('clamps dimensions to the expected range', function () {
    $calculator = new CivicRelevanceCalculator;

    $scores = $calculator->finalScores([
        ScoreDimensions::COMPREHENSIBILITY => 1.5,
        ScoreDimensions::ORIENTATION => -0.4,
        ScoreDimensions::REPRESENTATION => 0.6,
        ScoreDimensions::AGENCY => 0.2,
        ScoreDimensions::RELEVANCE => 0.9,
        ScoreDimensions::TIMELINESS => 2.1,
    ]);

    expect($scores[ScoreDimensions::COMPREHENSIBILITY])->toBe(1.0)
        ->and($scores[ScoreDimensions::ORIENTATION])->toBe(0.0)
        ->and($scores[ScoreDimensions::TIMELINESS])->toBe(1.0);
});

it('uses weights that sum to one', function () {
    $calculator = new CivicRelevanceCalculator;

    $sum = array_sum($calculator->weights());

    expect(abs($sum - 1.0))->toBeLessThan(0.0001);
});

it('computes a deterministic civic relevance score', function () {
    $calculator = new CivicRelevanceCalculator;

    $dimensions = [
        ScoreDimensions::COMPREHENSIBILITY => 0.8,
        ScoreDimensions::ORIENTATION => 0.6,
        ScoreDimensions::REPRESENTATION => 0.4,
        ScoreDimensions::AGENCY => 0.5,
        ScoreDimensions::RELEVANCE => 0.7,
        ScoreDimensions::TIMELINESS => 0.3,
    ];

    $first = $calculator->compute($dimensions);
    $second = $calculator->compute($dimensions);

    expect($first)->toBe($second);
});
