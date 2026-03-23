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
    protected $signature = 'enrich:backfill {--city=} {--limit=} {--latest=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue enrichment for articles with non-empty text';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = $this->eligibleArticlesQuery();
        $limit = $this->parsePositiveIntegerOption('limit');
        $latest = $this->parsePositiveIntegerOption('latest');
        $queued = 0;

        if ($limit === false || $latest === false) {
            return self::FAILURE;
        }

        if ($limit !== null && $latest !== null) {
            $this->error('Use either --limit or --latest, not both.');

            return self::FAILURE;
        }

        if ($latest !== null) {
            $articles = $query
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->orderByDesc('id')
                ->limit($latest)
                ->get();

            foreach ($articles as $article) {
                EnrichArticle::dispatch($article->id);
                $queued++;
            }
        } elseif ($limit !== null) {
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
        $cityOption = $this->option('city');

        $query = Article::query()
            ->whereHas('body', function ($query): void {
                $query->whereNotNull('cleaned_text')
                    ->whereRaw('length(trim(cleaned_text)) > 0');
            });

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

    private function parsePositiveIntegerOption(string $option): int|false|null
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            $this->error(sprintf('The --%s option must be a positive integer.', $option));

            return false;
        }

        return (int) $value;
    }
}
