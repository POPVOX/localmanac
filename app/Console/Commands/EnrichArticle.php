<?php

namespace App\Console\Commands;

use App\Jobs\EnrichArticle as EnrichArticleJob;
use App\Models\Article;
use Illuminate\Console\Command;

class EnrichArticle extends Command
{
    protected $signature = 'enrich:article {id : Article ID}';

    protected $description = 'Dispatch enrichment for an article';

    public function handle(): int
    {
        $id = (int) $this->argument('id');

        $article = Article::query()->find($id);

        if (! $article) {
            $this->error("Article not found: {$id}");

            return self::FAILURE;
        }

        EnrichArticleJob::dispatch($article->id);

        $this->info("Enrichment dispatched for article {$article->id}.");

        return self::SUCCESS;
    }
}
