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
