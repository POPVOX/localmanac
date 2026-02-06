<?php

namespace App\Console\Commands;

use App\Jobs\EnrichArticle;
use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class EnrichBackfill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enrich:backfill {--city=} {--limit=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue enrichment for articles with extractable text';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = $this->eligibleArticlesQuery();
        $limit = $this->option('limit');
        $queued = 0;

        if ($limit) {
            $articles = $query->limit((int) $limit)->get();

            foreach ($articles as $article) {
                EnrichArticle::dispatch($article->id);
                $queued++;
            }
        } else {
            $query->chunkById(100, function ($articles) use (&$queued): void {
                foreach ($articles as $article) {
                    EnrichArticle::dispatch($article->id);
                    $queued++;
                }
            });
        }

        $this->info("Queued enrichment for {$queued} article(s).");

        return self::SUCCESS;
    }

    private function eligibleArticlesQuery(): Builder
    {
        $minChars = (int) config('enrichment.min_cleaned_text_chars', 800);
        $cityOption = $this->option('city');

        $query = Article::query()
            ->whereHas('body', function ($query) use ($minChars): void {
                $query->whereNotNull('cleaned_text')
                    ->whereRaw('length(cleaned_text) >= ?', [$minChars]);
            })
            ->orderBy('id');

        if ($cityOption) {
            if (is_numeric($cityOption)) {
                $query->where('city_id', (int) $cityOption);
            } else {
                $query->whereHas('city', function ($query) use ($cityOption): void {
                    $query->where('slug', (string) $cityOption);
                });
            }
        }

        return $query;
    }
}
