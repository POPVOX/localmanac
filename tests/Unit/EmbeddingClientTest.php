<?php

use App\Services\Chat\EmbeddingClient;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

uses(TestCase::class);

it('returns empty embeddings for empty input lists', function () {
    $client = new EmbeddingClient;

    expect($client->embed([]))->toBe([]);
});

it('generates embeddings via laravel ai sdk', function () {
    config()->set('chat.vector_enabled', true);
    config()->set('chat.embedding_dimensions', 2);
    config()->set('chat.embedding_model', 'text-embedding-3-small');
    config()->set('chat.embedding_provider_chain', ['openai']);
    config()->set('chat.embedding_cache', false);

    Embeddings::fake([
        [
            [0.1, 0.2],
            [0.3, 0.4],
        ],
    ]);

    $client = new EmbeddingClient;
    $vectors = $client->embed([' first ', 'second ']);

    expect($vectors)->toBe([
        [0.1, 0.2],
        [0.3, 0.4],
    ]);

    Embeddings::assertGenerated(fn ($prompt) => $prompt->count() === 2
        && $prompt->dimensions === 2
        && $prompt->contains('first'));
});

it('returns an empty list when embeddings generation fails', function () {
    config()->set('chat.vector_enabled', true);
    config()->set('chat.embedding_dimensions', 2);
    config()->set('chat.embedding_provider_chain', ['openai']);
    config()->set('chat.embedding_cache', false);

    Embeddings::fake([
        fn (): never => throw new RuntimeException('Embeddings failed.'),
    ]);

    $client = new EmbeddingClient;

    expect($client->embed(['first']))->toBe([]);
});

it('throws when strict embedding generation returns an incomplete batch', function () {
    config()->set('chat.vector_enabled', true);
    config()->set('chat.embedding_dimensions', 2);
    config()->set('chat.embedding_provider_chain', ['openai']);
    config()->set('chat.embedding_cache', false);

    Embeddings::fake([
        [
            [0.1, 0.2],
        ],
    ]);

    $client = new EmbeddingClient;

    expect(fn () => $client->embedOrFail(['first', 'second']))
        ->toThrow(RuntimeException::class, 'returned 1 vector(s) for 2 input(s)');
});

it('throws when strict embedding generation returns the wrong dimensions', function () {
    config()->set('chat.vector_enabled', true);
    config()->set('chat.embedding_dimensions', 2);
    config()->set('chat.embedding_provider_chain', ['openai']);
    config()->set('chat.embedding_cache', false);

    Embeddings::fake([
        [
            [0.1, 0.2, 0.3],
        ],
    ]);

    $client = new EmbeddingClient;

    expect(fn () => $client->embedOrFail(['first']))
        ->toThrow(RuntimeException::class, 'has 3 dimensions; expected 2');
});

it('returns the first embedding for query embedding', function () {
    config()->set('chat.vector_enabled', true);
    config()->set('chat.embedding_dimensions', 2);
    config()->set('chat.embedding_provider_chain', ['openai']);
    config()->set('chat.embedding_cache', false);

    Embeddings::fake([
        [
            [0.5, 0.6],
        ],
    ]);

    $client = new EmbeddingClient;

    expect($client->embedQuery('question'))->toBe([0.5, 0.6]);
});

it('bounds multilingual batches by bytes and preserves vector order', function () {
    config(['chat.vector_enabled' => true, 'chat.embedding_dimensions' => 2,
        'chat.embedding_cache' => false, 'chat.embedding_batch_max_bytes' => 12,
        'chat.embedding_batch_max_inputs' => 128]);
    Embeddings::fake([[[1, 0], [2, 0]], [[3, 0]]]);
    expect((new EmbeddingClient)->embedOrFail(['城市', '居民', '服務']))
        ->toBe([[1.0, 0.0], [2.0, 0.0], [3.0, 0.0]]);
    Embeddings::assertGenerated(fn ($prompt) => $prompt->count() === 2);
    Embeddings::assertGenerated(fn ($prompt) => $prompt->count() === 1);
});

it('bounds batches by input count even for very short chunks', function () {
    config(['chat.vector_enabled' => true, 'chat.embedding_dimensions' => 2,
        'chat.embedding_cache' => false, 'chat.embedding_batch_max_inputs' => 2]);
    Embeddings::fake([[[1, 0], [2, 0]], [[3, 0]]]);
    expect((new EmbeddingClient)->embedOrFail(['a', 'b', 'c']))->toHaveCount(3);
    Embeddings::assertGenerated(fn ($prompt) => $prompt->count() === 2);
    Embeddings::assertGenerated(fn ($prompt) => $prompt->count() === 1);
});

it('rejects a later failed batch without returning partial vectors', function () {
    config(['chat.vector_enabled' => true, 'chat.embedding_dimensions' => 2,
        'chat.embedding_cache' => false, 'chat.embedding_batch_max_inputs' => 1]);
    Embeddings::fake([[[1, 0]], fn () => throw new RuntimeException('Second batch failed')]);
    expect(fn () => (new EmbeddingClient)->embedOrFail(['a', 'b']))
        ->toThrow(RuntimeException::class, 'Second batch failed');
});

it('rejects oversized individual inputs without truncating their text', function () {
    config(['chat.vector_enabled' => true]);
    Embeddings::fake();
    expect(fn () => (new EmbeddingClient)->embedOrFail([str_repeat('字', 3000)]))
        ->toThrow(RuntimeException::class, 'split the source into smaller chunks');
    Embeddings::assertNothingGenerated();
});
