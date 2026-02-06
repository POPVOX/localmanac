<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Articles\ArticleTextService;
use Illuminate\Console\Command;

class ArticlesRefreshText extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:refresh-text {--city=} {--limit=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh article titles and summaries using cleaned text or explainers';

    /**
     * Execute the console command.
     */
    public function handle(ArticleTextService $textService): int
    {
        $query = Article::query()->orderBy('id');
        $limit = $this->option('limit');
        $cityOption = $this->option('city');

        if ($cityOption) {
            if (is_numeric($cityOption)) {
                $query->where('city_id', (int) $cityOption);
            } else {
                $query->whereHas('city', function ($query) use ($cityOption): void {
                    $query->where('slug', (string) $cityOption);
                });
            }
        }

        $processed = 0;
        $updated = 0;

        if ($limit) {
            $articles = $query->limit((int) $limit)->get();

            foreach ($articles as $article) {
                if ($textService->refresh($article)) {
                    $updated++;
                }

                $processed++;
            }
        } else {
            $query->chunkById(100, function ($articles) use ($textService, &$processed, &$updated): void {
                foreach ($articles as $article) {
                    if ($textService->refresh($article)) {
                        $updated++;
                    }

                    $processed++;
                }
            });
        }

        $this->info("Refreshed {$updated} of {$processed} article(s).");

        return self::SUCCESS;
    }
}
