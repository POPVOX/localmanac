<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;

class Century2CalendarProfile extends AbstractJsonProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'century2_calendar';
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

        return $this->mapCentury2Calendar($items, $source, $timezone, $requestUrl);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, EventDTO>
     */
    private function mapCentury2Calendar(array $items, EventSource $source, string $timezone, string $sourceUrl): array
    {
        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->stringValue(data_get($item, 'Title'));
            $description = $this->stringValue(data_get($item, 'Description'));
            $startsAtValue = $this->stringValue(data_get($item, 'StartDateTime'));
            $endsAtValue = $this->stringValue(data_get($item, 'EndDateTime'));
            $eventUrl = $this->stringValue(data_get($item, 'URL'));
            $externalId = $this->stringValue(data_get($item, 'EventID'));

            if ($startsAtValue === '') {
                continue;
            }

            $startResult = $this->dateParser->parseIso($startsAtValue, $timezone);
            $endResult = $endsAtValue !== '' ? $this->dateParser->parseIso($endsAtValue, $timezone) : null;

            $startsAt = $startResult['starts_at'] ?? null;
            $endsAt = $endResult['starts_at'] ?? null;

            if (! $startsAt) {
                continue;
            }

            $normalizedEventUrl = $this->normalizer->normalizeUrl($eventUrl, $sourceUrl);
            $locationName = $this->parseCentury2LocationName($description);
            $allDay = $this->normalizeAllDay(null, $startResult['all_day'] ?? false, $endResult['all_day'] ?? false);
            $sourceHash = $this->buildCentury2SourceHash($item, $startsAtValue, $eventUrl);

            $results[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $startsAt,
                endsAt: $endsAt,
                allDay: $allDay,
                locationName: $locationName,
                locationAddress: null,
                description: $description !== '' ? $description : null,
                eventUrl: $normalizedEventUrl,
                externalId: $externalId !== '' ? $externalId : null,
                sourceUrl: $normalizedEventUrl,
                sourceHash: $sourceHash,
                rawPayload: [
                    'item' => $item,
                ],
            );
        }

        return $results;
    }

    private function parseCentury2LocationName(string $description): ?string
    {
        if ($description === '') {
            return null;
        }

        if (! preg_match_all('/<h4[^>]*>(.*?)<\/h4>/is', $description, $matches)) {
            return null;
        }

        foreach ($matches[1] as $match) {
            $candidate = trim(strip_tags($match));

            if ($candidate === '' || strtolower($candidate) === 'venue') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function buildCentury2SourceHash(array $item, string $startsAtValue, string $eventUrl): ?string
    {
        $externalId = $this->stringValue(data_get($item, 'EventID'));

        if ($externalId !== '' && $startsAtValue !== '') {
            return sha1("century2:{$externalId}:{$startsAtValue}");
        }

        if ($eventUrl !== '' && $startsAtValue !== '') {
            return sha1("century2:{$eventUrl}:{$startsAtValue}");
        }

        return null;
    }
}
