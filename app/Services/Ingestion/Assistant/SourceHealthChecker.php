<?php

namespace App\Services\Ingestion\Assistant;

use App\Models\EventSource;
use App\Models\Scraper;
use RuntimeException;
use Throwable;

class SourceHealthChecker
{
    public function __construct(
        private readonly SourceDiscoveryService $discovery,
        private readonly ScraperConfigPreviewer $scraperPreviewer,
        private readonly EventSourcePreviewer $eventPreviewer,
    ) {}

    /**
     * @return array{status: string, proposal: bool, error: string|null}
     */
    public function checkScraper(Scraper $scraper): array
    {
        try {
            $preview = $this->scraperPreviewer->preview(
                cityId: $scraper->city_id,
                organizationId: $scraper->organization_id,
                type: $scraper->type,
                sourceUrl: (string) $scraper->source_url,
                config: $scraper->config ?? [],
                scraperId: $scraper->id,
            );

            if (! $preview['valid']) {
                throw new RuntimeException(implode(' ', $preview['warnings']) ?: 'No article items were extracted.');
            }

            $this->markHealthy($scraper);

            return ['status' => 'healthy', 'proposal' => false, 'error' => null];
        } catch (Throwable $exception) {
            return $this->markUnhealthy($scraper, 'article', $exception);
        }
    }

    /**
     * @return array{status: string, proposal: bool, error: string|null}
     */
    public function checkEventSource(EventSource $source): array
    {
        try {
            $preview = $this->eventPreviewer->preview(
                cityId: $source->city_id,
                type: $source->source_type,
                sourceUrl: (string) $source->source_url,
                config: $source->config ?? [],
                sourceId: $source->id,
            );

            if (! $preview['valid']) {
                throw new RuntimeException(implode(' ', $preview['warnings']) ?: 'No dated events were extracted.');
            }

            $this->markHealthy($source);

            return ['status' => 'healthy', 'proposal' => false, 'error' => null];
        } catch (Throwable $exception) {
            return $this->markUnhealthy($source, 'event', $exception);
        }
    }

    private function markHealthy(Scraper|EventSource $source): void
    {
        $source->forceFill([
            'health_status' => 'healthy',
            'health_checked_at' => now(),
            'health_error' => null,
            'repair_proposal' => null,
        ])->save();
    }

    /**
     * @return array{status: string, proposal: bool, error: string|null}
     */
    private function markUnhealthy(Scraper|EventSource $source, string $expectedKind, Throwable $exception): array
    {
        $error = trim($exception->getMessage()) ?: 'The source did not return previewable items.';
        $proposal = null;

        try {
            $discovery = $this->discovery->discover((string) $source->source_url);

            if ($discovery['kind'] === $expectedKind && $this->proposalPreviews($source, $discovery)) {
                $proposal = [
                    'kind' => $discovery['kind'],
                    'type' => $discovery['type'],
                    'source_url' => $discovery['source_url'],
                    'config' => $discovery['config'],
                    'confidence' => $discovery['confidence'],
                    'summary' => $this->proposalSummary($source, $discovery),
                    'generated_at' => now()->toIso8601String(),
                ];
            }
        } catch (Throwable) {
            // The original health failure remains the useful operator-facing error.
        }

        $source->forceFill([
            'health_status' => 'unhealthy',
            'health_checked_at' => now(),
            'health_error' => mb_substr($error, 0, 2000),
            'repair_proposal' => $proposal,
        ])->save();

        return [
            'status' => 'unhealthy',
            'proposal' => $proposal !== null,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $discovery
     */
    private function proposalPreviews(Scraper|EventSource $source, array $discovery): bool
    {
        if ($source instanceof Scraper) {
            return $this->scraperPreviewer->preview(
                cityId: $source->city_id,
                organizationId: $source->organization_id,
                type: $discovery['type'],
                sourceUrl: $discovery['source_url'],
                config: $discovery['config'],
                scraperId: $source->id,
            )['valid'];
        }

        return $this->eventPreviewer->preview(
            cityId: $source->city_id,
            type: $discovery['type'],
            sourceUrl: $discovery['source_url'],
            config: $discovery['config'],
            sourceId: $source->id,
        )['valid'];
    }

    /**
     * @param  array<string, mixed>  $discovery
     */
    private function proposalSummary(Scraper|EventSource $source, array $discovery): string
    {
        $currentType = $source instanceof Scraper ? $source->type : $source->source_type;
        $changes = [];

        if ($currentType !== $discovery['type']) {
            $changes[] = "switch {$currentType} to {$discovery['type']}";
        }

        if ((string) $source->source_url !== $discovery['source_url']) {
            $changes[] = 'use the discovered endpoint';
        }

        if (($source->config ?? []) !== $discovery['config']) {
            $changes[] = 'refresh the extraction settings';
        }

        return $changes === []
            ? 'Re-apply the verified extraction settings.'
            : 'Verified repair: '.implode(', ', $changes).'.';
    }
}
