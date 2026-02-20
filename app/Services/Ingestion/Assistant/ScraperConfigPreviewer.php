<?php

namespace App\Services\Ingestion\Assistant;

use App\Models\Scraper;
use App\Services\Ingestion\Fetchers\DocumentersFetcher;
use App\Services\Ingestion\Fetchers\GenericListingFetcher;
use App\Services\Ingestion\Fetchers\RssFetcher;
use App\Services\Ingestion\Fetchers\WichitaArchivePdfListFetcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ScraperConfigPreviewer
{
    public function __construct(
        private readonly RssFetcher $rssFetcher,
        private readonly DocumentersFetcher $documentersFetcher,
        private readonly GenericListingFetcher $genericListingFetcher,
        private readonly WichitaArchivePdfListFetcher $wichitaArchivePdfListFetcher,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, items: array<int, array<string, string|null>>, warnings: array<int, string>}
     */
    public function preview(
        int $cityId,
        ?int $organizationId,
        string $type,
        string $sourceUrl,
        array $config,
        ?int $scraperId = null,
    ): array {
        $scraper = new Scraper;
        $scraper->forceFill([
            'id' => $scraperId,
            'city_id' => $cityId,
            'organization_id' => $organizationId,
            'name' => 'Preview Scraper',
            'slug' => 'preview-scraper',
            'type' => $type,
            'source_url' => $sourceUrl,
            'config' => $config,
            'is_enabled' => true,
        ]);

        $warnings = [];

        $items = match ($type) {
            'rss' => $this->rssFetcher->fetch($scraper),
            'html' => $this->fetchHtmlPreviewItems($scraper, $warnings),
            default => throw new InvalidArgumentException('Preview is only supported for rss and html scraper types.'),
        };

        $maxItems = max(1, (int) config('scraper-assistant.preview.max_items', 5));

        $previewItems = collect($items)
            ->map(fn (array $item): array => $this->mapPreviewItem($item))
            ->filter(fn (array $item): bool => ($item['title'] ?? null) !== null && ($item['source_url'] ?? null) !== null)
            ->take($maxItems)
            ->values()
            ->all();

        if ($previewItems === []) {
            $warnings[] = 'No previewable items were extracted from this draft config.';
        }

        return [
            'valid' => $previewItems !== [],
            'items' => $previewItems,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function fetchHtmlPreviewItems(Scraper $scraper, array &$warnings): array
    {
        $profile = Arr::get($scraper->config, 'profile');

        if (! is_string($profile) || trim($profile) === '') {
            throw new InvalidArgumentException('Config profile is required for html scraper preview.');
        }

        return match ($profile) {
            'wichitadocumenters' => $this->documentersFetcher->fetch($scraper),
            'generic_listing' => $this->genericListingFetcher->fetch($scraper),
            'wichita_archive_pdf_list' => $this->mapArchivePreview($scraper, $warnings),
            default => throw new InvalidArgumentException('Unsupported html scraper profile for preview.'),
        };
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function mapArchivePreview(Scraper $scraper, array &$warnings): array
    {
        $result = $this->wichitaArchivePdfListFetcher->fetch($scraper);

        $skipped = Arr::get($result, 'meta.skipped', []);

        if (is_array($skipped) && $skipped !== []) {
            $warnings[] = 'Archive fetch skipped '.array_sum(array_map(
                fn (mixed $count): int => is_numeric($count) ? (int) $count : 0,
                $skipped,
            )).' candidate rows while parsing.';
        }

        return is_array($result['items'] ?? null)
            ? $result['items']
            : [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{title: string|null, source_url: string|null, content_type: string|null, published_at: string|null, summary: string|null}
     */
    private function mapPreviewItem(array $item): array
    {
        $title = $this->normalizeNullableString($item['title'] ?? null);
        $sourceUrl = $this->normalizeNullableString(
            Arr::get($item, 'source.source_url')
            ?? ($item['canonical_url'] ?? null)
        );

        $summary = $this->normalizeNullableString($item['summary'] ?? null);

        if ($summary !== null) {
            $summary = Str::limit($summary, 200, '');
        }

        return [
            'title' => $title,
            'source_url' => $sourceUrl,
            'content_type' => $this->normalizeNullableString($item['content_type'] ?? Arr::get($item, 'source.source_type')),
            'published_at' => $this->normalizePublishedAt($item['published_at'] ?? null),
            'summary' => $summary,
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePublishedAt(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
