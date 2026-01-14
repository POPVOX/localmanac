<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class WichitaLibnetLibcalProfile extends AbstractJsonProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'wichita_libnet_libcal';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{request_url: string, payload: mixed}>
     */
    public function fetchPayloads(EventSource $source, array $config, string $timezone): array
    {
        $requestUrl = $this->buildWichitaLibnetRequest($source->source_url ?? '', $config, $timezone);
        $response = Http::timeout(15)->retry(2, 250)->get($requestUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch JSON feed');
        }

        return [[
            'request_url' => $requestUrl,
            'payload' => $response->json(),
        ]];
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

        return $this->mapWichitaLibnetLibcal($items, $source, $timezone, $requestUrl);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildWichitaLibnetRequest(string $sourceUrl, array $config, string $timezone): string
    {
        $reqBase = Arr::get($config, 'req', []);

        if (! is_array($reqBase)) {
            $reqBase = [];
        }

        $days = Arr::get($config, 'days', 43);
        $days = is_numeric($days) ? (int) $days : 43;

        $payload = array_merge($reqBase, [
            'date' => Carbon::now($timezone)->format('Y-m-d'),
            'days' => $days,
        ]);

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $baseUrl = $this->stripQueryFromUrl($sourceUrl);
        $query = array_merge($this->extractQueryParams($sourceUrl), [
            'req' => $encodedPayload,
        ]);

        return $baseUrl.'?'.http_build_query($query);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, EventDTO>
     */
    private function mapWichitaLibnetLibcal(array $items, EventSource $source, string $timezone, string $sourceUrl): array
    {
        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->stringValue(data_get($item, 'title'));
            $description = $this->stringValue(data_get($item, 'long_description'));

            if ($description === '') {
                $description = $this->stringValue(data_get($item, 'description'));
            }

            $eventStartValue = $this->stringValue(data_get($item, 'event_start'));
            $startsAtValue = $eventStartValue !== ''
                ? $eventStartValue
                : $this->stringValue(data_get($item, 'raw_start_time'));

            $endsAtValue = $this->stringValue(data_get($item, 'event_end'));

            if ($endsAtValue === '') {
                $endsAtValue = $this->stringValue(data_get($item, 'raw_end_time'));
            }

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

            $locationName = $this->stringValue(data_get($item, 'location'));

            if ($locationName === '') {
                $locationName = $this->stringValue(data_get($item, 'library'));
            }

            $eventUrl = $this->normalizeLibnetUrl($this->stringValue(data_get($item, 'url')), $sourceUrl);
            $sourceHash = $this->buildWichitaLibnetSourceHash($item, $eventStartValue);
            $externalId = $this->stringValue(data_get($item, 'id'));

            $results[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $startsAt,
                endsAt: $endsAt,
                allDay: false,
                locationName: $locationName !== '' ? $locationName : null,
                locationAddress: null,
                description: $description !== '' ? $description : null,
                eventUrl: $eventUrl,
                externalId: $externalId !== '' ? $externalId : null,
                sourceUrl: $eventUrl,
                sourceHash: $sourceHash,
                rawPayload: [
                    'item' => $item,
                ],
            );
        }

        return $results;
    }

    private function normalizeLibnetUrl(string $url, ?string $baseUrl): ?string
    {
        $normalized = $this->normalizer->normalizeUrl($url, $baseUrl);

        if (! $normalized) {
            return null;
        }

        $cleaned = preg_replace('#(?<!:)/{2,}#', '/', $normalized);

        return $cleaned ?: $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function buildWichitaLibnetSourceHash(array $item, string $eventStartValue): ?string
    {
        $externalId = $this->stringValue(data_get($item, 'id'));

        if ($externalId === '') {
            return null;
        }

        if ($eventStartValue !== '') {
            return sha1("libnet:{$externalId}:{$eventStartValue}");
        }

        return sha1("libnet:{$externalId}");
    }
}
