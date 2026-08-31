<?php

use App\Services\Chat\ReciprocalRankFusion;
use Tests\TestCase;

uses(TestCase::class);

it('fuses candidate ranks without comparing raw score scales', function () {
    config()->set('chat.retrieval_rrf_k', 60);
    config()->set('chat.retrieval_candidate_limit', 10);

    $results = app(ReciprocalRankFusion::class)->fuse([
        'dense' => [
            ['id' => 'dense-only', 'score' => 100000],
            ['id' => 'shared', 'score' => 0.01],
        ],
        'lexical' => [
            ['id' => 'shared', 'score' => 0.00001],
            ['id' => 'lexical-only', 'score' => 999999],
        ],
    ], 'id');

    expect($results[0]['id'])->toBe('shared')
        ->and($results[0]['rrf_contributions'])->toHaveCount(2)
        ->and(collect($results)->pluck('id')->all())->toBe([
            'shared',
            'dense-only',
            'lexical-only',
        ]);
});

it('uses deterministic identity ordering for equal ranks', function () {
    config()->set('chat.retrieval_candidate_limit', 10);

    $results = app(ReciprocalRankFusion::class)->fuse([
        'dense' => [
            ['id' => 'b'],
        ],
        'lexical' => [
            ['id' => 'a'],
        ],
    ], 'id');

    expect(collect($results)->pluck('id')->all())->toBe(['a', 'b']);
});
