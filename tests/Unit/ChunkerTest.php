<?php

use App\Services\Chat\Ingestion\Chunker;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('chat.chunk_max_chars', 40);
    config()->set('chat.chunk_overlap_chars', 0);
    config()->set('chat.chunk_min_chars', 1);
});

it('retains accumulated text before an oversized paragraph', function () {
    $intro = 'Introductory paragraph stays indexed.';
    $oversized = str_repeat('oversized paragraph content ', 5).'TAIL-MARKER';

    $chunks = app(Chunker::class)->chunk($intro."\n\n".$oversized);

    expect($chunks)
        ->not->toBeEmpty()
        ->and($chunks[0])->toBe($intro)
        ->and(implode(' ', $chunks))->toContain('TAIL-MARKER');
});

it('consumes every part of a sentence longer than the chunk limit', function () {
    $text = str_repeat('A', 95).'END';

    $chunks = app(Chunker::class)->chunk($text);

    expect($chunks)->toHaveCount(3)
        ->and(implode('', $chunks))->toBe($text)
        ->and(collect($chunks)->every(fn (string $chunk): bool => mb_strlen($chunk) <= 40))->toBeTrue();
});

it('keeps a short final chunk when it cannot be merged without exceeding the limit', function () {
    config()->set('chat.chunk_min_chars', 20);

    $text = str_repeat('B', 40).'tail';
    $chunks = app(Chunker::class)->chunk($text);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1])->toBe('tail');
});

it('keeps overlap plus the next paragraph within the chunk limit', function () {
    config()->set('chat.chunk_overlap_chars', 15);

    $chunks = app(Chunker::class)->chunk(
        str_repeat('A', 30)."\n\n".str_repeat('B', 35)
    );

    expect(collect($chunks)->every(fn (string $chunk): bool => mb_strlen($chunk) <= 40))->toBeTrue()
        ->and(implode(' ', $chunks))->toContain(str_repeat('B', 35));
});
