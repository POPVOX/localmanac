<?php

namespace App\Services\Ingestion;

use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\Fetchers\WichitaArchivePdfListFetcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ScrapeRunner
{
    public function __construct(
        private readonly Deduplicator $deduplicator,
        private readonly ArticleWriter $writer,
        private readonly RssFetcher $rssFetcher,
    ) {}

    public function run(Scraper $scraper): ScraperRun
    {
        $run = $this->createRun($scraper);

        return $this->runExisting($run);
    }

    public function createRun(Scraper $scraper): ScraperRun
    {
        $this->assertRunnable($scraper);

        return ScraperRun::create([
            'scraper_id' => $scraper->id,
            'city_id' => $scraper->city_id,
            'status' => 'queued',
            'items_found' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'meta' => [],
        ]);
    }

    public function runExisting(ScraperRun $run): ScraperRun
    {
        $run->loadMissing('scraper');

        $scraper = $run->scraper;

        if (! $scraper) {
            throw new InvalidArgumentException('Scraper is missing for this run');
        }

        $this->assertRunnable($scraper);

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
        ])->save();

        $skipped = 0;
        $itemsFound = 0;
        $created = 0;
        $updated = 0;
        $fetchMeta = [];
        $skippedReasons = [
            'missing_required' => 0,
        ];

        try {
            $result = $this->fetchItems($scraper);
            $items = $result['items'];
            $fetchMeta = $result['meta'];
            $itemsFound = count($items);

            foreach ($items as $item) {
                $source = $item['source'] ?? [];
                if (! ($item['city_id'] ?? null) || ! ($item['title'] ?? null) || ! ($source['source_url'] ?? null)) {
                    $skipped++;
                    $skippedReasons['missing_required']++;

                    continue;
                }

                $existing = $this->deduplicator->findExisting($item);
                $article = $this->writer->write($item, $existing);
                $article->loadMissing('body');

                $this->dispatchPdfExtractionIfNeeded($article, $item);

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
                'meta' => array_merge($run->meta ?? [], [
                    'skipped_items' => $skipped,
                    'skipped_reasons' => $skippedReasons,
                    'scraper_type' => $scraper->type,
                    'profile' => Arr::get($scraper->config, 'profile'),
                    'href_contains' => Arr::get($scraper->config, 'list.href_contains'),
                    'fetch_meta' => $fetchMeta,
                    'source_url' => $scraper->config['feed_url'] ?? $scraper->source_url,
                ]),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'items_found' => $itemsFound,
                'items_created' => $created,
                'items_updated' => $updated,
                'error_message' => $e->getMessage(),
                'meta' => array_merge($run->meta ?? [], [
                    'skipped_items' => $skipped,
                    'scraper_type' => $scraper->type,
                    'skipped_reasons' => $skippedReasons,
                    'fetch_meta' => $fetchMeta,
                    'profile' => Arr::get($scraper->config, 'profile'),
                    'href_contains' => Arr::get($scraper->config, 'list.href_contains'),
                    'source_url' => $scraper->config['feed_url'] ?? $scraper->source_url,
                    'exception_class' => $e::class,
                    'trace_id' => (string) Str::uuid(),
                ]),
            ]);
        }

        return $run->refresh();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function fetchItems(Scraper $scraper): array
    {
        return match ($scraper->type) {
            'rss' => [
                'items' => $this->rssFetcher->fetch($scraper),
                'meta' => [],
            ],
            'html' => $this->fetchHtmlItems($scraper),
            default => throw new InvalidArgumentException('Unsupported scraper type'),
        };
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function fetchHtmlItems(Scraper $scraper): array
    {
        $profile = Arr::get($scraper->config, 'profile');

        return match ($profile) {
            'wichitadocumenters' => [
                'items' => app(\App\Services\Ingestion\Fetchers\DocumentersFetcher::class)->fetch($scraper),
                'meta' => [],
            ],
            'generic_listing' => [
                'items' => app(\App\Services\Ingestion\Fetchers\GenericListingFetcher::class)->fetch($scraper),
                'meta' => [],
            ],
            'wichita_archive_pdf_list' => app(WichitaArchivePdfListFetcher::class)->fetch($scraper),
            default => throw new InvalidArgumentException(
                "No HTML fetcher for profile: {$profile}. Supported: wichitadocumenters, generic_listing, wichita_archive_pdf_list"
            ),
        };
    }

    private function assertRunnable(Scraper $scraper): void
    {
        if (! $scraper->is_enabled) {
            throw new InvalidArgumentException('Scraper is disabled');
        }

        if (! in_array($scraper->type, ['rss', 'html'], true)) {
            throw new InvalidArgumentException('Unsupported scraper type');
        }
    }

    private function dispatchPdfExtractionIfNeeded(Article $article, array $item): void
    {
        if (($item['content_type'] ?? null) !== 'pdf') {
            return;
        }

        $body = $article->body;

        if ($body && $body->extracted_at !== null) {
            return;
        }

        $source = $item['source'] ?? [];
        $pdfUrl = $source['source_url'] ?? ($item['canonical_url'] ?? null);

        if (! $pdfUrl) {
            return;
        }

        ExtractPdfBody::dispatch($article->id, $pdfUrl);
    }
}
