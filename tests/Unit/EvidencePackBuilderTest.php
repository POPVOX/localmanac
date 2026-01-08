<?php

use App\Services\Extraction\EvidencePackBuilder;

it('builds deterministic evidence packs', function () {
    $builder = new EvidencePackBuilder;
    $text = str_repeat('Opening segment. ', 80)
        ."\n\n"
        .'Tuesday, October 21, 2025 at 9:00 A.M. Meeting at City Hall.'
        ."\n\n"
        .str_repeat('Tail segment. ', 80);

    $first = $builder->build($text);
    $second = $builder->build($text);

    expect($first->packText)->toBe($second->packText);
});

it('always includes the opening slice', function () {
    $builder = new EvidencePackBuilder;
    $text = str_repeat('Opening sentence. ', 120).str_repeat('Later text. ', 200);
    $opening = substr($text, 0, 200);

    $result = $builder->build($text);

    expect(str_contains($result->packText, $opening))->toBeTrue();
});

it('includes later event time and date clusters', function () {
    $builder = new EvidencePackBuilder;
    $text = 'Published October 1, 2025. Intro paragraph.'
        ."\n\n"
        .str_repeat('Background details. ', 60)
        ."\n\n"
        .'Tuesday, October 21, 2025 at 9:00 A.M. The council will meet to review the agenda.'
        ."\n\n"
        .str_repeat('Additional context. ', 40);

    $result = $builder->build($text);

    expect($result->packText)->toContain('October 21')
        ->and($result->packText)->toContain('9:00');
});

it('rebuilds when time signals are missed in the first pass', function () {
    $builder = new EvidencePackBuilder;

    $dateBlockOne = 'Monday, January 10, 2025 the meeting will be held by the council. ';
    $dateBlockTwo = 'Wednesday, February 5, 2025 the meeting will be held by the board. ';
    $dateBlockThree = 'Friday, March 12, 2025 the meeting will be held by the commission. ';
    $filler = str_repeat('Long introduction text. ', 80);
    $timeBlock = 'AFFIDAVIT: Tuesday, October 21, 2025 at 9:00 A.M. notarized text. ';
    $tail = str_repeat('Background narrative without times. ', 120);

    $text = $dateBlockOne.$dateBlockTwo.$dateBlockThree.$filler.$timeBlock.$tail;

    $result = $builder->build($text);

    expect($result->rebuildUsed)->toBeTrue()
        ->and($result->packText)->toContain('9:00');
});
