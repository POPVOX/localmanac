<?php

namespace App\Console\Commands;

use App\Jobs\EmbedArticleChunks;
use App\Jobs\EmbedChatSourcePage;
use App\Models\Article;
use App\Models\ArticleChunk;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Services\Chat\Ingestion\ArticleChunkEmbedder;
use App\Services\Chat\Ingestion\ChatSourcePageEmbedder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class AuditChatEmbeddings extends Command
{
    /** @var string */
    protected $signature = 'chat:audit-embeddings
                            {--city= : City ID or slug}
                            {--repair : Rebuild documents with missing or stale embeddings}
                            {--sync : Run requested repairs synchronously}
                            {--limit=100 : Maximum repairs per index}';

    /** @var string */
    protected $description = 'Audit chat and article chunk coverage and optionally repair missing or stale embeddings.';

    public function handle(
        ChatSourcePageEmbedder $pageEmbedder,
        ArticleChunkEmbedder $articleEmbedder,
    ): int {
        $model = (string) config('chat.embedding_model', 'text-embedding-3-small');
        $chatChunks = $this->scopedChatChunks();
        $articleChunks = $this->scopedArticleChunks();
        $chatPages = $this->repairableChatPages($model);
        $articles = $this->repairableArticles($model);

        $this->table(
            ['Index', 'Chunks', 'With vector', 'Missing vector', 'Wrong model', 'Documents to repair'],
            [
                $this->summaryRow('Chat sources', $chatChunks, $chatPages, $model),
                $this->summaryRow('Articles', $articleChunks, $articles, $model),
            ],
        );

        if (! $this->option('repair')) {
            if ($chatPages->exists() || $articles->exists()) {
                $this->warn('Embedding gaps found. Re-run with --repair to queue safe rebuilds.');
            } else {
                $this->info('Embedding coverage and model metadata are current.');
            }

            return self::SUCCESS;
        }

        if (! config('chat.vector_enabled', true)) {
            $this->error('Embedding repair requires CHAT_VECTOR_ENABLED=true.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sync = (bool) $this->option('sync');
        $queue = (string) config('chat.embedding_queue', 'embedding');
        $repaired = 0;
        $failed = 0;

        foreach ($chatPages->limit($limit)->get() as $page) {
            try {
                if ($sync) {
                    $pageEmbedder->embed($page);
                } else {
                    EmbedChatSourcePage::dispatch($page->id)->onQueue($queue);
                }

                $repaired++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error("Chat source page {$page->id}: {$exception->getMessage()}");
            }
        }

        foreach ($articles->with('body')->limit($limit)->get() as $article) {
            try {
                if ($sync) {
                    $articleEmbedder->embed($article);
                } else {
                    EmbedArticleChunks::dispatch($article->id)->onQueue($queue);
                }

                $repaired++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error("Article {$article->id}: {$exception->getMessage()}");
            }
        }

        $verb = $sync ? 'Rebuilt' : 'Queued';
        $this->info("{$verb} {$repaired} document(s) for embedding repair.");

        if ($failed > 0) {
            $this->error("Embedding repair failures: {$failed}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  Builder<ChatSourceChunk>|Builder<ArticleChunk>  $chunks
     * @param  Builder<ChatSourcePage>|Builder<Article>  $documents
     * @return array<int, int|string>
     */
    private function summaryRow(string $label, Builder $chunks, Builder $documents, string $model): array
    {
        return [
            $label,
            (clone $chunks)->count(),
            (clone $chunks)->whereNotNull('embedding')->count(),
            (clone $chunks)->whereNull('embedding')->count(),
            (clone $chunks)->whereNotNull('embedding')->where(function (Builder $query) use ($model): void {
                $query->whereNull('embedding_model')->orWhere('embedding_model', '!=', $model);
            })->count(),
            (clone $documents)->count(),
        ];
    }

    /** @return Builder<ChatSourceChunk> */
    private function scopedChatChunks(): Builder
    {
        $query = ChatSourceChunk::query();
        $city = $this->cityOption();

        if ($city !== null) {
            $query->whereHas('page.source.city', fn (Builder $builder) => $this->applyCity($builder, $city));
        }

        return $query;
    }

    /** @return Builder<ArticleChunk> */
    private function scopedArticleChunks(): Builder
    {
        $query = ArticleChunk::query();
        $city = $this->cityOption();

        if ($city !== null) {
            $query->whereHas('article.city', fn (Builder $builder) => $this->applyCity($builder, $city));
        }

        return $query;
    }

    /** @return Builder<ChatSourcePage> */
    private function repairableChatPages(string $model): Builder
    {
        $query = ChatSourcePage::query()
            ->whereNotNull('content_text')
            ->where('content_text', '!=', '')
            ->where(function (Builder $builder) use ($model): void {
                $builder->whereDoesntHave('chunks')
                    ->orWhereHas('chunks', function (Builder $chunks) use ($model): void {
                        $chunks->whereNull('embedding')
                            ->orWhereNull('embedding_model')
                            ->orWhere('embedding_model', '!=', $model);
                    });
            })
            ->orderBy('id');

        $city = $this->cityOption();

        if ($city !== null) {
            $query->whereHas('source.city', fn (Builder $builder) => $this->applyCity($builder, $city));
        }

        return $query;
    }

    /** @return Builder<Article> */
    private function repairableArticles(string $model): Builder
    {
        $query = Article::query()
            ->where('status', 'published')
            ->whereHas('body', fn (Builder $body) => $body->whereNotNull('cleaned_text')->where('cleaned_text', '!=', ''))
            ->where(function (Builder $builder) use ($model): void {
                $builder->whereDoesntHave('articleChunks')
                    ->orWhereHas('articleChunks', function (Builder $chunks) use ($model): void {
                        $chunks->whereNull('embedding')
                            ->orWhereNull('embedding_model')
                            ->orWhere('embedding_model', '!=', $model);
                    });
            })
            ->orderBy('id');

        $city = $this->cityOption();

        if ($city !== null) {
            $query->whereHas('city', fn (Builder $builder) => $this->applyCity($builder, $city));
        }

        return $query;
    }

    private function cityOption(): ?string
    {
        $city = trim((string) $this->option('city'));

        return $city === '' ? null : $city;
    }

    private function applyCity(Builder $query, string $city): void
    {
        if (ctype_digit($city)) {
            $query->whereKey((int) $city);

            return;
        }

        $query->where('slug', $city);
    }
}
