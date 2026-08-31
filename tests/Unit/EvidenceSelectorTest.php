<?php

use App\Services\Chat\EvidenceSelector;
use Tests\TestCase;

uses(TestCase::class);

it('promotes source diversity before filling deferred candidates', function () {
    config()->set('chat.retrieval_max_evidence_per_source', 1);
    config()->set('chat.retrieval_context_token_budget', 1000);

    $evidence = collect([
        ['id' => 'a1', 'source_url' => 'https://a.test/page', 'snippet' => 'First A'],
        ['id' => 'a2', 'source_url' => 'https://a.test/page', 'snippet' => 'Second A'],
        ['id' => 'b1', 'source_url' => 'https://b.test/page', 'snippet' => 'First B'],
    ]);

    $selected = app(EvidenceSelector::class)->select($evidence, 3);

    expect($selected->pluck('id')->all())->toBe(['a1', 'b1', 'a2']);
});

it('stops before exceeding the configured context budget', function () {
    config()->set('chat.retrieval_max_evidence_per_source', 3);
    config()->set('chat.retrieval_context_token_budget', 10);

    $evidence = collect([
        ['id' => 'one', 'source_url' => 'https://a.test', 'snippet' => str_repeat('a', 20)],
        ['id' => 'two', 'source_url' => 'https://b.test', 'snippet' => str_repeat('b', 24)],
        ['id' => 'three', 'source_url' => 'https://c.test', 'snippet' => str_repeat('c', 20)],
    ]);

    $selected = app(EvidenceSelector::class)->select($evidence, 3);

    expect($selected->pluck('id')->all())->toBe(['one', 'three']);
});

it('keeps a truncated top result when it alone exceeds the budget', function () {
    config()->set('chat.retrieval_max_evidence_per_source', 3);
    config()->set('chat.retrieval_context_token_budget', 5);

    $selected = app(EvidenceSelector::class)->select(collect([
        ['id' => 'long', 'source_url' => 'https://a.test', 'snippet' => str_repeat('x', 100)],
    ]), 3);

    expect($selected)->toHaveCount(1)
        ->and($selected->first()['snippet'])->toHaveLength(20);
});
