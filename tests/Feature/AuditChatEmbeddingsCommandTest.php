<?php

use App\Jobs\EmbedArticleChunks;
use App\Jobs\EmbedChatSourcePage;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use Illuminate\Support\Facades\Queue;

it('reports embedding gaps without mutating the index', function () {
    config()->set('chat.embedding_model', 'current-model');

    $page = ChatSourcePage::factory()->create([
        'content_text' => 'Current page content.',
    ]);
    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    $this->artisan('chat:audit-embeddings')
        ->expectsOutputToContain('Embedding gaps found')
        ->assertSuccessful();

    expect(ChatSourceChunk::query()->count())->toBe(1);
});

it('queues safe repairs for chat pages and articles', function () {
    Queue::fake();
    config()->set('chat.embedding_model', 'current-model');
    config()->set('chat.embedding_queue', 'embedding');

    $page = ChatSourcePage::factory()->create([
        'content_text' => 'A page that has not been embedded.',
    ]);

    $article = Article::factory()->create();
    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => 'An article that has not been embedded.',
    ]);

    $this->artisan('chat:audit-embeddings', ['--repair' => true])
        ->expectsOutputToContain('Queued 2 document(s)')
        ->assertSuccessful();

    Queue::assertPushed(EmbedChatSourcePage::class, fn (EmbedChatSourcePage $job): bool => $job->chatSourcePageId === $page->id
        && $job->queue === 'embedding');
    Queue::assertPushed(EmbedArticleChunks::class, fn (EmbedArticleChunks $job): bool => $job->articleId === $article->id
        && $job->queue === 'embedding');
});
