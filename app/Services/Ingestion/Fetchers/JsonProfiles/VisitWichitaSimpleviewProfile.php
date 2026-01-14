<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class VisitWichitaSimpleviewProfile extends AbstractJsonProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'visit_wichita_simpleview';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{request_url: string, payload: mixed}>
     */
    public function fetchPayloads(EventSource $source, array $config, string $timezone): array
    {
        $sourceConfig = $source->config ?? [];
        [$requestUrl, $query] = $this->buildVisitWichitaRequest($source->source_url ?? '', $sourceConfig, $timezone);

        $response = Http::timeout(15)->retry(2, 250)->withOptions(['query' => $query])->get($requestUrl);

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

        return $this->mapVisitWichitaSimpleview($items, $source, $timezone);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, EventDTO>
     */
    private function mapVisitWichitaSimpleview(array $items, EventSource $source, string $timezone): array
    {
        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->stringValue(data_get($item, 'title'));
            $description = $this->stringValue(data_get($item, 'description'));
            $locationName = $this->stringValue(data_get($item, 'location'));

            if ($locationName === '') {
                $locationName = $this->stringValue(data_get($item, 'listing.title'));
            }

            $locationAddress = $this->buildLocationAddress([
                $this->stringValue(data_get($item, 'address1')),
                $this->stringValue(data_get($item, 'city')),
                $this->stringValue(data_get($item, 'state')),
            ]);

            $eventUrl = $this->normalizer->normalizeUrl(
                $this->stringValue(data_get($item, 'url')),
                'https://www.visitwichita.com'
            );

            $dateValue = $this->stringValue(data_get($item, 'date'));
            $startTime = $this->stringValue(data_get($item, 'startTime'));
            $date = $this->parseVisitWichitaDate($dateValue, $timezone);

            if (! $date) {
                continue;
            }

            $localDate = $date->format('Y-m-d');
            $allDay = $startTime === '';
            $startsAt = $allDay
                ? $this->parseLocalDateTime($localDate, $timezone)?->startOfDay()
                : $this->parseLocalDateTime("{$localDate} {$startTime}", $timezone);

            if (! $startsAt) {
                continue;
            }

            $sourceHash = $this->buildVisitWichitaSourceHash(
                $item,
                $dateValue,
                $startTime !== '' ? $startTime : 'all_day',
                $eventUrl,
                $startsAt,
            );

            $externalId = $this->stringValue(data_get($item, 'recid'));

            $results[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $startsAt,
                endsAt: null,
                allDay: $allDay,
                locationName: $locationName !== '' ? $locationName : null,
                locationAddress: $locationAddress !== '' ? $locationAddress : null,
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

    /**
     * @param  array<string, mixed>  $sourceConfig
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildVisitWichitaRequest(string $sourceUrl, array $sourceConfig, string $timezone): array
    {
        $token = $this->stringValue(Arr::get($sourceConfig, 'auth.token'));

        if ($token === '') {
            throw new InvalidArgumentException('Visit Wichita token missing in config (auth.token)');
        }

        $existingQuery = $this->extractQueryParams($sourceUrl);
        $jsonPayload = $this->normalizeVisitWichitaJsonPayload(
            $existingQuery['json']
                ?? Arr::get($sourceConfig, 'json.query')
                ?? Arr::get($sourceConfig, 'json.payload')
                ?? Arr::get($sourceConfig, 'auth.json'),
            $timezone
        );
        $baseUrl = $this->stripQueryFromUrl($sourceUrl);
        $requestQuery = [
            'json' => $jsonPayload,
            'token' => $token,
        ];

        Log::debug('Visit Wichita Simpleview request prepared.', [
            'url' => $baseUrl,
            'token_present' => true,
        ]);

        return [$baseUrl, $requestQuery];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVisitWichitaDefaultPayload(string $timezone): array
    {
        $categories = [
            '37',
            '34',
            '36',
            '62',
            '46',
            '35',
            '39',
            '45',
            '41',
            '42',
            '59',
            '43',
            '44',
            '71',
            '40',
            '66',
            '48',
            '47',
            '38',
            '69',
            '68',
            '49',
            '67',
        ];

        $start = Carbon::now($timezone)->startOfDay();
        $end = Carbon::now($timezone)->addMonth()->startOfDay();

        return [
            'filter' => [
                'active' => true,
                'eventTypeId' => [
                    '$ne' => 13,
                ],
                'date_range' => [
                    'start' => [
                        '$date' => $start->copy()->utc()->toIso8601String(),
                    ],
                    'end' => [
                        '$date' => $end->copy()->utc()->toIso8601String(),
                    ],
                ],
                '$and' => [
                    [
                        'categories.catId' => [
                            '$in' => $categories,
                        ],
                    ],
                ],
            ],
            'options' => [
                'limit' => 200,
                'skip' => 0,
                'count' => true,
                'castDocs' => false,
                'fields' => [
                    'recid' => 1,
                    'title' => 1,
                    'description' => 1,
                    'location' => 1,
                    'address1' => 1,
                    'city' => 1,
                    'state' => 1,
                    'url' => 1,
                    'date' => 1,
                    'startTime' => 1,
                    'listing.title' => 1,
                ],
            ],
        ];
    }

    private function normalizeVisitWichitaJsonPayload(mixed $value, string $timezone): string
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return json_encode($this->buildVisitWichitaDefaultPayload($timezone), JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function buildVisitWichitaSourceHash(
        array $item,
        string $dateValue,
        string $timeToken,
        ?string $eventUrl,
        Carbon $startsAt,
    ): ?string {
        $recId = $this->stringValue(data_get($item, 'recid'));

        if ($recId !== '' && $dateValue !== '') {
            return "visitwichita:{$recId}:{$dateValue}:{$timeToken}";
        }

        if ($eventUrl) {
            return sha1($eventUrl.'|'.$startsAt->toIso8601String());
        }

        return null;
    }

    private function buildLocationAddress(array $parts): string
    {
        $parts = array_values(array_filter($parts, fn (string $part) => $part !== ''));

        return $parts === [] ? '' : implode(', ', $parts);
    }

    private function parseVisitWichitaDate(string $value, string $timezone): ?Carbon
    {
        $value = $this->stringValue($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseLocalDateTime(string $value, string $timezone): ?Carbon
    {
        $value = $this->stringValue($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
