<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Scraper;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ArticlesPurgeScraperData extends Command
{
    protected $signature = 'articles:purge-scraper-data
        {--scraper=* : Scraper ID or slug, repeatable}
        {--city= : City ID or slug}
        {--generic-listing : Target html generic_listing scrapers}
        {--apply : Delete matching articles}';

    protected $description = 'Audit or purge article rows for selected scrapers';

    public function handle(): int
    {
        $scrapers = $this->targetScrapers();

        if ($scrapers->isEmpty()) {
            $this->error('No matching scrapers found. Pass --scraper or use --generic-listing with an optional --city filter.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('apply')) {
            $this->line('Audit mode only. Pass --apply to delete matching articles.');
        }

        $totalDeleted = 0;
        $totalArticles = 0;

        foreach ($scrapers as $scraper) {
            $articleQuery = Article::query()->where('scraper_id', $scraper->id);
            $articleCount = (clone $articleQuery)->count();

            $this->line("{$scraper->name} ({$scraper->slug})");
            $this->line('  articles: '.$articleCount);

            $totalArticles += $articleCount;

            if (! (bool) $this->option('apply') || $articleCount === 0) {
                $this->line('  deleted: 0');

                continue;
            }

            $deleted = 0;

            $articleQuery
                ->orderBy('id')
                ->chunkById(100, function ($articles) use (&$deleted): void {
                    foreach ($articles as $article) {
                        $article->delete();
                        $deleted++;
                    }
                });

            $this->line('  deleted: '.$deleted);
            $totalDeleted += $deleted;
        }

        $this->newLine();
        $this->info('Totals');
        $this->line('  scrapers: '.$scrapers->count());
        $this->line('  articles: '.$totalArticles);
        $this->line('  deleted: '.$totalDeleted);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Scraper>
     */
    private function targetScrapers(): Collection
    {
        $scraperOptions = collect($this->option('scraper'))
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->values();

        $city = $this->stringOption('city');
        $genericListing = (bool) $this->option('generic-listing');

        if ($scraperOptions->isEmpty() && ! $genericListing) {
            return collect();
        }

        return Scraper::query()
            ->with('city')
            ->orderBy('id')
            ->get()
            ->filter(function (Scraper $scraper) use ($scraperOptions, $city, $genericListing): bool {
                $matchesScraper = $scraperOptions->isNotEmpty()
                    ? $scraperOptions->contains(function (string $identifier) use ($scraper): bool {
                        return ctype_digit($identifier)
                            ? $scraper->id === (int) $identifier
                            : $scraper->slug === $identifier;
                    })
                    : false;

                $matchesGenericListing = $genericListing
                    && $scraper->type === 'html'
                    && ($scraper->config['profile'] ?? null) === 'generic_listing';

                if (! $matchesScraper && ! $matchesGenericListing) {
                    return false;
                }

                if ($city === null) {
                    return true;
                }

                return ctype_digit($city)
                    ? $scraper->city_id === (int) $city
                    : $scraper->city?->slug === $city;
            })
            ->values();
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
