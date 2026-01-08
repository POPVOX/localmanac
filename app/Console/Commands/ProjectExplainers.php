<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Analysis\ArticleExplainerProjector;
use Illuminate\Console\Command;

class ProjectExplainers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'project:explainers {--city=} {--limit=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build explainer projections for articles with LLM analysis';

    /**
     * Execute the console command.
     */
    public function handle(ArticleExplainerProjector $projector): int
    {
        $cityId = $this->option('city');
        $limit = $this->option('limit');

        $query = Article::query()
            ->when($cityId, fn ($query) => $query->where('city_id', (int) $cityId))
            ->whereHas('analysis', fn ($query) => $query->where('status', 'llm_done'))
            ->orderBy('id');

        $count = 0;

        if ($limit) {
            $articles = $query->limit((int) $limit)->get();

            foreach ($articles as $article) {
                $projector->projectForArticle($article);
                $count++;
            }
        } else {
            $query->chunkById(100, function ($articles) use (&$count, $projector): void {
                foreach ($articles as $article) {
                    $projector->projectForArticle($article);
                    $count++;
                }
            });
        }

        $this->info("Projected explainers for {$count} article(s).");

        return self::SUCCESS;
    }
}
