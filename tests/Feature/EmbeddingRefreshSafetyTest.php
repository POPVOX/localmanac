<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleChunk;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Services\Chat\Ingestion\ArticleChunkEmbedder;
use App\Services\Chat\Ingestion\ChatSourcePageEmbedder;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    config()->set('chat.vector_enabled', true);
    config()->set('chat.embedding_dimensions', 2);
    config()->set('chat.embedding_model', 'test-embedding-model');
    config()->set('chat.embedding_provider_chain', ['openai']);
    config()->set('chat.embedding_cache', false);
    config()->set('chat.chunk_max_chars', 200);
    config()->set('chat.chunk_overlap_chars', 0);
    config()->set('chat.chunk_min_chars', 1);
});

it('preserves chat source chunks when replacement embeddings fail', function () {
    $page = ChatSourcePage::factory()->create([
        'content_text' => 'Replacement civic information for this source page.',
    ]);

    $existing = ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'content' => 'Previously indexed civic information.',
        'content_length' => 37,
        'embedding_model' => 'previous-model',
        'embedding' => '[0.9,0.8]',
    ]);

    Embeddings::fake([
        fn (): never => throw new RuntimeException('Provider unavailable.'),
    ]);

    expect(fn () => app(ChatSourcePageEmbedder::class)->embed($page))
        ->toThrow(RuntimeException::class, 'Provider unavailable.');

    expect(ChatSourceChunk::query()->where('chat_source_page_id', $page->id)->count())->toBe(1)
        ->and($existing->refresh()->content)->toBe('Previously indexed civic information.')
        ->and($existing->embedding_model)->toBe('previous-model');
});

it('preserves article chunks when replacement embeddings are incomplete', function () {
    $article = Article::factory()->create();
    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => 'Replacement article information for the city.',
    ]);
    $article->load('body');

    $existing = ArticleChunk::factory()->create([
        'article_id' => $article->id,
        'content' => 'Previously indexed article information.',
        'content_length' => 39,
        'embedding_model' => 'previous-model',
        'embedding' => '[0.9,0.8]',
    ]);

    Embeddings::fake([
        [[]],
    ]);

    expect(fn () => app(ArticleChunkEmbedder::class)->embed($article))
        ->toThrow(RuntimeException::class, 'has 0 dimensions; expected 2');

    expect(ArticleChunk::query()->where('article_id', $article->id)->count())->toBe(1)
        ->and($existing->refresh()->content)->toBe('Previously indexed article information.')
        ->and($existing->embedding_model)->toBe('previous-model');
});

it('atomically replaces chat source chunks after a complete embedding response', function () {
    $page = ChatSourcePage::factory()->create([
        'content_text' => 'Current information about a city service.',
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'content' => 'Stale information.',
        'content_length' => 18,
    ]);

    Embeddings::fake([
        [[0.1, 0.2]],
    ]);

    $count = app(ChatSourcePageEmbedder::class)->embed($page);
    $chunk = ChatSourceChunk::query()->where('chat_source_page_id', $page->id)->sole();

    expect($count)->toBe(1)
        ->and($chunk->content)->toBe('Current information about a city service.')
        ->and($chunk->embedding_model)->toBe('test-embedding-model')
        ->and($chunk->embedding)->toBe('[0.1,0.2]');
});
