<?php

namespace App\Services\Ingestion\Assistant;

use App\Models\City;
use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\Fetchers\HtmlCalendarFetcher;
use App\Services\Ingestion\Fetchers\IcsFetcher;
use App\Services\Ingestion\Fetchers\JsonApiFetcher;
use App\Services\Ingestion\Fetchers\RssEventsFetcher;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EventSourcePreviewer
{
    public function __construct(
        private readonly IcsFetcher $icsFetcher,
        private readonly RssEventsFetcher $rssFetcher,
        private readonly JsonApiFetcher $jsonFetcher,
        private readonly HtmlCalendarFetcher $htmlFetcher,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, items: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function preview(int $cityId, string $type, string $sourceUrl, array $config, ?int $sourceId = null): array
    {
        $source = new EventSource;
        $source->forceFill([
            'id' => $sourceId,
            'city_id' => $cityId,
            'name' => 'Preview Event Source',
            'source_type' => $type,
            'source_url' => $sourceUrl,
            'config' => $this->applyPreviewLimits($type, $config),
            'is_active' => true,
        ]);
        $source->setRelation('city', City::query()->find($cityId));

        $items = match ($type) {
            'ics' => $this->icsFetcher->fetch($source),
            'rss' => $this->rssFetcher->fetch($source),
            'json', 'json_api' => $this->jsonFetcher->fetch($source),
            'html' => $this->htmlFetcher->fetch($source),
            default => throw new InvalidArgumentException('Unsupported event source type for preview.'),
        };

        $maxItems = max(1, (int) config('scraper-assistant.preview.max_items', 5));
        $previewItems = collect($items)
            ->filter(fn (mixed $item): bool => $item instanceof EventDTO && trim($item->title) !== '' && $item->startsAt !== null)
            ->take($maxItems)
            ->map(fn (EventDTO $item): array => [
                'title' => $item->title,
                'starts_at' => $item->startsAt?->toIso8601String(),
                'ends_at' => $item->endsAt?->toIso8601String(),
                'location' => $item->locationName,
                'source_url' => $item->eventUrl ?? $item->sourceUrl,
                'summary' => $item->description ? Str::limit(strip_tags($item->description), 200, '') : null,
            ])
            ->values()
            ->all();

        return [
            'valid' => $previewItems !== [],
            'items' => $previewItems,
            'warnings' => $previewItems === [] ? ['No dated events were found with this configuration.'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyPreviewLimits(string $type, array $config): array
    {
        if ($type === 'html') {
            data_set($config, 'list.max_items', min((int) data_get($config, 'list.max_items', 5), 5));
            data_set($config, 'detail.max_detail_fetches', min((int) data_get($config, 'detail.max_detail_fetches', 3), 3));
        }

        if (in_array($type, ['json', 'json_api'], true) && (int) data_get($config, 'json.months_forward', 0) > 0) {
            data_set($config, 'json.months_forward', min((int) data_get($config, 'json.months_forward'), 2));
        }

        return $config;
    }
}
