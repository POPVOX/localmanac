<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\Chat\Ingestion\ArticleChunkEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EmbedArticleChunks implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $articleId,
    ) {}

    public function handle(ArticleChunkEmbedder $embedder): void
    {
        $article = Article::query()->with('body')->find($this->articleId);

        if (! $article) {
            return;
        }

        $embedder->embed($article);
    }
}
