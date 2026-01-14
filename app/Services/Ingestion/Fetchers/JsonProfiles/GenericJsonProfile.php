<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use Illuminate\Support\Arr;

class GenericJsonProfile extends AbstractJsonProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'generic';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{request_url: string, payload: mixed}>
     */
    public function fetchPayloads(EventSource $source, array $config, string $timezone): array
    {
        return $this->fetchJsonPayloads($source->source_url ?? '', $config, $timezone);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, EventDTO>
     */
    public function mapToEvents(mixed $payload, EventSource $source, array $config, string $timezone, string $requestUrl): array
    {
        $listPath = $this->resolveListPath($config);
        $items = $listPath === '' ? $payload : data_get($payload, $listPath, []);

        if (! is_array($items)) {
            return [];
        }

        $mapping = Arr::get($config, 'mapping', []);

        return $this->mapGenericItems($items, $mapping, $timezone, $requestUrl);
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, string>  $mapping
     * @return array<int, EventDTO>
     */
    private function mapGenericItems(array $items, array $mapping, string $timezone, string $sourceUrl): array
    {
        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = (string) $this->getValue($item, $mapping, 'title', ['title', 'name']);
            $startsAtValue = $this->stringValue(
                $this->getValue($item, $mapping, 'starts_at', ['starts_at', 'start', 'start_time', 'start_date'])
            );
            $endsAtValue = $this->stringValue(
                $this->getValue($item, $mapping, 'ends_at', ['ends_at', 'end', 'end_time', 'end_date'])
            );
            $locationName = $this->stringValue(
                $this->getValue($item, $mapping, 'location_name', ['location_name', 'location', 'venue.name'])
            );
            $locationAddress = $this->stringValue(
                $this->getValue($item, $mapping, 'location_address', ['location_address', 'venue.address'])
            );
            $description = $this->stringValue(
                $this->getValue($item, $mapping, 'description', ['description', 'summary'])
            );
            $eventUrl = $this->stringValue(
                $this->getValue($item, $mapping, 'event_url', ['event_url', 'url', 'link'])
            );
            $externalId = $this->stringValue(
                $this->getValue($item, $mapping, 'external_id', ['id', 'external_id', 'uid'])
            );
            $allDayValue = $this->getValue($item, $mapping, 'all_day', ['all_day']);

            $startResult = $startsAtValue !== ''
                ? $this->dateParser->parseIso($startsAtValue, $timezone)
                : null;
            $endResult = $endsAtValue !== ''
                ? $this->dateParser->parseIso($endsAtValue, $timezone)
                : null;

            $startsAt = $startResult['starts_at'] ?? null;
            $endsAt = $endResult['starts_at'] ?? null;

            if (! $startsAt) {
                continue;
            }

            $allDay = $this->normalizeAllDay($allDayValue, $startResult['all_day'] ?? false, $endResult['all_day'] ?? false);
            $normalizedEventUrl = $this->normalizer->normalizeUrl($eventUrl, $sourceUrl);

            $results[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $startsAt,
                endsAt: $endsAt,
                allDay: $allDay,
                locationName: $locationName !== '' ? $locationName : null,
                locationAddress: $locationAddress !== '' ? $locationAddress : null,
                description: $description !== '' ? $description : null,
                eventUrl: $normalizedEventUrl,
                externalId: $externalId !== '' ? $externalId : null,
                sourceUrl: $normalizedEventUrl,
                rawPayload: [
                    'item' => $item,
                ],
            );
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $mapping
     * @param  array<int, string>  $fallbacks
     */
    private function getValue(array $item, array $mapping, string $key, array $fallbacks): mixed
    {
        if (isset($mapping[$key])) {
            return data_get($item, $mapping[$key]);
        }

        foreach ($fallbacks as $fallback) {
            $value = data_get($item, $fallback);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
