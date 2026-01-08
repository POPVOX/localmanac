<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Analysis\ProcessTimelineProjector;
use Illuminate\Console\Command;

class ProjectProcessTimeline extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'project:process-timeline {--article_id=} {--city_id=} {--limit=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build process timeline projections for articles with LLM analysis';

    /**
     * Execute the console command.
     */
    public function handle(ProcessTimelineProjector $projector): int
    {
        $articleId = $this->option('article_id');
        $cityId = $this->option('city_id');
        $limit = $this->option('limit');

        $query = Article::query()
            ->when($articleId, fn ($query) => $query->where('id', (int) $articleId))
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
            $query->chunkById(100, function ($articles) use (&$count, $projector) {
                foreach ($articles as $article) {
                    $projector->projectForArticle($article);
                    $count++;
                }
            });
        }

        $this->info("Projected process timelines for {$count} article(s).");

        return self::SUCCESS;
    }
}
