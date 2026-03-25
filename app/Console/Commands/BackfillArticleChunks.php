<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Chat\Ingestion\ArticleChunkEmbedder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class BackfillArticleChunks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:backfill-article-chunks
                            {--force : Re-embed articles that already have chunks}
                            {--batch-size=50 : Number of articles per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill article chunks and embeddings for published articles.';

    /**
     * Execute the console command.
     */
    public function handle(ArticleChunkEmbedder $embedder): int
    {
        $query = $this->eligibleArticlesQuery();
        $batchSize = (int) $this->option('batch-size');

        if ($batchSize < 1) {
            $batchSize = 50;
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No eligible articles found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$total} article(s) in batches of {$batchSize}...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $chunksCreated = 0;
        $failures = 0;

        $this->eligibleArticlesQuery()->chunkById($batchSize, function ($articles) use ($embedder, $bar, &$processed, &$chunksCreated, &$failures): bool {
            foreach ($articles as $article) {
                try {
                    $count = $embedder->embed($article);
                    $chunksCreated += $count;
                    $processed++;
                } catch (Throwable $exception) {
                    report($exception);
                    $failures++;
                }

                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Articles processed: {$processed}");
        $this->info("Chunks created: {$chunksCreated}");

        if ($failures > 0) {
            $this->warn("Failures: {$failures}");
        }

        return self::SUCCESS;
    }

    private function eligibleArticlesQuery(): Builder
    {
        $query = Article::query()
            ->where('status', 'published')
            ->whereHas('body', fn (Builder $q) => $q->whereNotNull('cleaned_text')->where('cleaned_text', '!=', ''))
            ->with('body')
            ->orderBy('id');

        if (! $this->option('force')) {
            $query->whereDoesntHave('articleChunks');
        }

        return $query;
    }
}
