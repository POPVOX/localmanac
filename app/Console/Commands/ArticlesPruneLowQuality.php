<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Ingestion\ArticleQualityGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ArticlesPruneLowQuality extends Command
{
    protected $signature = 'articles:prune-low-quality
                            {--city= : City id or slug}
                            {--scraper= : Scraper id or slug}
                            {--reason=* : Restrict to one or more guard reasons}
                            {--limit= : Max articles to inspect}
                            {--force : Delete matching articles (otherwise dry run)}';

    protected $description = 'Find and optionally delete low-quality articles based on ingestion guard rules';

    public function handle(ArticleQualityGuard $qualityGuard): int
    {
        $selectedReasons = $this->normalizeReasons((array) $this->option('reason'), $qualityGuard);

        if ($selectedReasons === null) {
            return self::FAILURE;
        }

        $query = $this->articlesQuery();
        $limit = $this->option('limit');

        if (is_numeric($limit) && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $forceDelete = (bool) $this->option('force');
        $inspected = 0;
        $matched = 0;
        $deleted = 0;
        $reasonCounts = [];
        $sampleRows = [];

        $processBatch = function (Collection $articles) use (
            $qualityGuard,
            $selectedReasons,
            $forceDelete,
            &$inspected,
            &$matched,
            &$deleted,
            &$reasonCounts,
            &$sampleRows
        ): void {
            foreach ($articles as $article) {
                $inspected++;
                $reason = $qualityGuard->rejectionReason($this->articleAsItem($article));

                if ($reason === null) {
                    continue;
                }

                if ($selectedReasons !== [] && ! in_array($reason, $selectedReasons, true)) {
                    continue;
                }

                $matched++;
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;

                if (count($sampleRows) < 15) {
                    $sampleRows[] = [
                        (string) $article->id,
                        $reason,
                        (string) $article->title,
                        (string) ($article->primarySourceUrl() ?? $article->canonical_url ?? ''),
                    ];
                }

                if ($forceDelete) {
                    $article->delete();
                    $deleted++;
                }
            }
        };

        if (is_numeric($limit) && (int) $limit > 0) {
            $processBatch($query->get());
        } else {
            $query->chunkById(100, function (Collection $articles) use ($processBatch): void {
                $processBatch($articles);
            });
        }

        if ($sampleRows !== []) {
            $this->table(['ID', 'Reason', 'Title', 'URL'], $sampleRows);
        }

        $this->line("inspected: {$inspected}");
        $this->line("matched: {$matched}");

        foreach ($reasonCounts as $reason => $count) {
            $this->line("matched_{$reason}: {$count}");
        }

        if ($forceDelete) {
            $this->info("deleted: {$deleted}");
        } else {
            $this->warn('Dry run only. Re-run with --force to delete matched articles.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeReasons(array $reasonOptions, ArticleQualityGuard $qualityGuard): ?array
    {
        $selectedReasons = collect($reasonOptions)
            ->filter(fn (mixed $reason): bool => is_string($reason) && trim($reason) !== '')
            ->map(fn (mixed $reason): string => trim((string) $reason))
            ->values()
            ->all();

        if ($selectedReasons === []) {
            return [];
        }

        $knownReasons = $qualityGuard->knownReasons();

        foreach ($selectedReasons as $reason) {
            if (! in_array($reason, $knownReasons, true)) {
                $this->error("Unknown reason `{$reason}`. Available: ".implode(', ', $knownReasons));

                return null;
            }
        }

        return $selectedReasons;
    }

    private function articlesQuery(): Builder
    {
        $query = Article::query()
            ->with([
                'body',
                'sources' => fn ($query) => $query->orderBy('id'),
            ])
            ->orderBy('id');

        $cityOption = $this->option('city');
        if (is_string($cityOption) && trim($cityOption) !== '') {
            if (ctype_digit(trim($cityOption))) {
                $query->where('city_id', (int) trim($cityOption));
            } else {
                $citySlug = trim($cityOption);
                $query->whereHas('city', function (Builder $query) use ($citySlug): void {
                    $query->where('slug', $citySlug);
                });
            }
        }

        $scraperOption = $this->option('scraper');
        if (is_string($scraperOption) && trim($scraperOption) !== '') {
            if (ctype_digit(trim($scraperOption))) {
                $query->where('scraper_id', (int) trim($scraperOption));
            } else {
                $scraperSlug = trim($scraperOption);
                $query->whereHas('scraper', function (Builder $query) use ($scraperSlug): void {
                    $query->where('slug', $scraperSlug);
                });
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function articleAsItem(Article $article): array
    {
        $source = $article->sources->first();

        return [
            'title' => $article->title,
            'summary' => $article->summary,
            'content_type' => $article->content_type,
            'canonical_url' => $article->canonical_url,
            'body' => [
                'cleaned_text' => $article->body?->cleaned_text,
            ],
            'source' => [
                'source_url' => $source?->source_url ?? $article->canonical_url,
                'source_type' => $source?->source_type,
            ],
        ];
    }
}
