<?php

namespace App\Services\Ingestion;

use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Ingestion\Fetchers\RssFetcher;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Throwable;

class ScrapeRunner
{
    public function __construct(
        private readonly Deduplicator $deduplicator,
        private readonly ArticleWriter $writer,
        private readonly RssFetcher $rssFetcher,
    ) {
    }

    public function run(Scraper $scraper): ScraperRun
    {
        if (! $scraper->is_enabled) {
            throw new InvalidArgumentException('Scraper is disabled');
        }

        if (! in_array($scraper->type, ['rss', 'html'], true)) {
            throw new InvalidArgumentException('Unsupported scraper type');
        }

        $run = ScraperRun::create([
            'scraper_id' => $scraper->id,
            'city_id' => $scraper->city_id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $skipped = 0;

        try {
            $items = $this->fetchItems($scraper);
            $itemsFound = count($items);
            $created = 0;
            $updated = 0;

            foreach ($items as $item) {
                $source = $item['source'] ?? [];
                if (! ($item['city_id'] ?? null) || ! ($item['title'] ?? null) || ! ($source['source_url'] ?? null)) {
                    $skipped++;
                    continue;
                }

                $existing = $this->deduplicator->findExisting($item);
                $this->writer->write($item, $existing);

                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }
            }

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_created' => $created,
                'items_updated' => $updated,
                'meta' => [
                    'skipped_items' => $skipped,
                    'scraper_type' => $scraper->type,
                    'source_url' => $scraper->config['feed_url'] ?? $scraper->source_url,
                ],
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
                'meta' => [
                    'skipped_items' => $skipped,
                    'scraper_type' => $scraper->type,
                    'exception_class' => $e::class,
                ],
            ]);
        }

        return $run;
    }

    private function fetchItems(Scraper $scraper): array
    {
        return match ($scraper->type) {
            'rss' => $this->rssFetcher->fetch($scraper),
            'html' => $this->fetchHtmlItems($scraper),
            default => throw new InvalidArgumentException('Unsupported scraper type'),
        };
    }

    private function fetchHtmlItems(Scraper $scraper): array
    {
        $profile = Arr::get($scraper->config, 'profile');

        return match ($profile) {
            'wichitadocumenters' => app(\App\Services\Ingestion\Fetchers\DocumentersFetcher::class)->fetch($scraper),
            'generic_listing' => app(\App\Services\Ingestion\Fetchers\GenericListingFetcher::class)->fetch($scraper),
            default => throw new InvalidArgumentException(
                "No HTML fetcher for profile: {$profile}. Supported: wichitadocumenters, generic_listing"
            ),
        };
    }
}
