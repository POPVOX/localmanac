<?php

namespace App\Services\Ingestion;

use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\Fetchers\WichitaArchivePdfListFetcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ScrapeRunner
{
    public function __construct(
        private readonly Deduplicator $deduplicator,
        private readonly ArticleWriter $writer,
        private readonly RssFetcher $rssFetcher,
        private readonly ?PostgresSequenceSynchronizer $sequenceSynchronizer = null,
        private readonly ?ArticleQualityGuard $qualityGuard = null,
    ) {}

    public function run(Scraper $scraper): ScraperRun
    {
        $run = $this->createRun($scraper);

        return $this->runExisting($run);
    }

    public function createRun(Scraper $scraper): ScraperRun
    {
        $this->assertRunnable($scraper);

        try {
            return $this->persistRun($scraper);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isRecoverableRunPrimaryKeyViolation($exception)) {
                throw $exception;
            }

            $recovered = $this->resolveRunSequenceDrift();

            if (! $recovered) {
                throw $exception;
            }

            Log::warning('Recovered from Postgres sequence drift while creating a scraper run.', [
                'scraper_id' => $scraper->id,
            ]);

            return $this->persistRun($scraper);
        }
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

                $qualityRejectionReason = $this->qualityRejectionReason($item);

                if ($qualityRejectionReason !== null) {
                    $skipped++;
                    $skippedReasons[$qualityRejectionReason] = ($skippedReasons[$qualityRejectionReason] ?? 0) + 1;

                    continue;
                }

                $existing = $this->deduplicator->findExisting($item);
                $article = $this->writer->write($item, $existing);
                $article->loadMissing('body');

                $this->dispatchDocumentExtractionIfNeeded($article, $item);

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
            'documenters', 'wichitadocumenters' => [
                'items' => app(\App\Services\Ingestion\Fetchers\DocumentersFetcher::class)->fetch($scraper),
                'meta' => [],
            ],
            'generic_listing' => [
                'items' => app(\App\Services\Ingestion\Fetchers\GenericListingFetcher::class)->fetch($scraper),
                'meta' => [],
            ],
            'civicplus_archive_pdf_list', 'wichita_archive_pdf_list' => app(WichitaArchivePdfListFetcher::class)->fetch($scraper),
            default => throw new InvalidArgumentException(
                "No HTML fetcher for profile: {$profile}. Supported: documenters, generic_listing, civicplus_archive_pdf_list"
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

    private function dispatchDocumentExtractionIfNeeded(Article $article, array $item): void
    {
        if (! $this->isDocumentItem($item)) {
            return;
        }

        $body = $article->body;

        if ($body && $body->extracted_at !== null && ! $this->shouldRetryFailedDocumentExtraction($body->extraction_status, $body->extraction_error)) {
            return;
        }

        $source = $item['source'] ?? [];
        $documentUrl = $source['source_url'] ?? ($item['canonical_url'] ?? null);

        if (! $documentUrl) {
            return;
        }

        ExtractPdfBody::dispatch($article->id, $documentUrl);
    }

    private function isDocumentItem(array $item): bool
    {
        $source = is_array($item['source'] ?? null) ? $item['source'] : [];
        $contentType = mb_strtolower((string) ($item['content_type'] ?? ''));
        $sourceType = mb_strtolower((string) ($source['source_type'] ?? ''));
        $url = (string) ($source['source_url'] ?? ($item['canonical_url'] ?? ''));
        $url = mb_strtolower($url);

        $documentTypes = ['pdf', 'doc', 'docx', 'document'];

        if (in_array($contentType, $documentTypes, true)) {
            return true;
        }

        if (in_array($sourceType, $documentTypes, true)) {
            return true;
        }

        if (preg_match('/\.(pdf|docx?)($|\?)/', $url) === 1) {
            return true;
        }

        return str_contains($url, 'archive.aspx?adid=');
    }

    private function shouldRetryFailedDocumentExtraction(?string $status, ?string $error): bool
    {
        if ($status !== 'failed') {
            return false;
        }

        if ($error === null) {
            return false;
        }

        return $error === 'Non-PDF response detected';
    }

    protected function persistRun(Scraper $scraper): ScraperRun
    {
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

    private function isRecoverableRunPrimaryKeyViolation(UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (! str_contains($message, 'duplicate key value violates unique constraint')) {
            return false;
        }

        return str_contains($message, '"scraper_runs_pkey"');
    }

    private function resolveRunSequenceDrift(): bool
    {
        $synchronizer = $this->sequenceSynchronizer ?? new PostgresSequenceSynchronizer;

        return $synchronizer->syncTables(['scraper_runs']);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function qualityRejectionReason(array $item): ?string
    {
        $guard = $this->qualityGuard ?? app(ArticleQualityGuard::class);

        return $guard->rejectionReason($item);
    }
}
