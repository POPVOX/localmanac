<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\EventSource;
use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\EventNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class JsonApiFetcher implements EventSourceFetcher
{
    public function __construct(
        private readonly CalendarDateParser $dateParser,
        private readonly EventNormalizer $normalizer,
    ) {}

    /**
     * @return array<int, EventDTO>
     */
    public function fetch(EventSource $source): array
    {
        if (! in_array($source->source_type, ['json', 'json_api'], true)) {
            throw new InvalidArgumentException('EventSource type must be json or json_api');
        }

        $sourceUrl = $source->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $sourceConfig = $source->config ?? [];
        $config = Arr::get($sourceConfig, 'json', $sourceConfig);
        $profile = Arr::get($sourceConfig, 'profile') ?? Arr::get($config, 'profile');

        $timezone = Arr::get($config, 'timezone') ?? $source->city?->timezone ?? 'UTC';
        $http = Http::timeout(15)->retry(2, 250);
        $payloads = [];

        if ($profile === 'visit_wichita_simpleview') {
            [$requestUrl, $query] = $this->buildVisitWichitaRequest($sourceUrl, $sourceConfig, $timezone);
            $response = $http->withOptions(['query' => $query])->get($requestUrl);
            $sourceUrl = $requestUrl;
            if (! $response->successful()) {
                throw new InvalidArgumentException('Failed to fetch JSON feed');
            }
            $payloads[$sourceUrl] = $response->json();
        } elseif ($profile === 'wichita_libnet_libcal') {
            $requestUrl = $this->buildWichitaLibnetRequest($sourceUrl, $config, $timezone);
            $response = $http->get($requestUrl);
            $sourceUrl = $requestUrl;
            if (! $response->successful()) {
                throw new InvalidArgumentException('Failed to fetch JSON feed');
            }
            $payloads[$sourceUrl] = $response->json();
        } else {
            $payloads = $this->fetchJsonPayloads($sourceUrl, $config, $timezone, $http);
        }

        $listPath = Arr::get($config, 'list_path');

        if ($listPath === null) {
            $listPath = Arr::get($config, 'root_path');
        }

        if ($listPath === null) {
            throw new InvalidArgumentException('EventSource config.json.list_path or config.json.root_path is required');
        }

        $mapping = Arr::get($config, 'mapping', []);
        $results = [];

        foreach ($payloads as $requestUrl => $payload) {
            $items = $listPath === '' ? $payload : data_get($payload, $listPath, []);

            if (! is_array($items)) {
                continue;
            }

            if ($profile === 'visit_wichita_simpleview') {
                return $this->mapVisitWichitaSimpleview($items, $source, $timezone);
            }

            if ($profile === 'wichita_libnet_libcal') {
                return $this->mapWichitaLibnetLibcal($items, $source, $timezone, $requestUrl);
            }

            if ($profile === 'century2_calendar') {
                $results = array_merge(
                    $results,
                    $this->mapCentury2Calendar($items, $source, $timezone, $requestUrl)
                );

                continue;
            }

            $results = array_merge(
                $results,
                $this->mapGenericItems($items, $mapping, $timezone, $requestUrl)
            );
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function fetchJsonPayloads(string $sourceUrl, array $config, string $timezone, mixed $http): array
    {
        $payloads = [];
        $urls = $this->shouldUseMonthLoop($config)
            ? $this->buildMonthLoopUrls($sourceUrl, $config, $timezone)
            : [$sourceUrl];

        foreach ($urls as $url) {
            $response = $http->get($url);

            if (! $response->successful()) {
                throw new InvalidArgumentException('Failed to fetch JSON feed');
            }

            $payloads[$url] = $response->json();
        }

        return $payloads;
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

    /**
     * @param  array<string, mixed>  $config
     */
    private function shouldUseMonthLoop(array $config): bool
    {
        return $this->resolveMonthsForward($config) > 0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveMonthsForward(array $config): int
    {
        $monthsForward = Arr::get($config, 'months_forward');

        if ($monthsForward === null) {
            $monthsForward = Arr::get($config, 'month_loop') ? 1 : 0;
        }

        if (! is_numeric($monthsForward)) {
            return 0;
        }

        $monthsForward = (int) $monthsForward;

        return $monthsForward > 0 ? $monthsForward : 0;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function buildMonthLoopUrls(string $sourceUrl, array $config, string $timezone): array
    {
        $template = $this->stringValue(Arr::get($config, 'url_template'));

        if ($template === '') {
            $template = $sourceUrl;
        }

        if (! str_contains($template, '{year}') || ! str_contains($template, '{month}')) {
            throw new InvalidArgumentException('Month loop requires {year} and {month} placeholders in the URL template.');
        }

        $monthsForward = $this->resolveMonthsForward($config);
        $startMonth = $this->resolveStartMonth($config, $timezone);
        $monthQuery = $this->normalizeMonthQuery(Arr::get($config, 'month_query', []));
        $urls = [];

        for ($offset = 0; $offset < $monthsForward; $offset++) {
            $month = $startMonth->copy()->addMonthsNoOverflow($offset);
            $url = str_replace(
                ['{year}', '{month}'],
                [$month->format('Y'), $month->format('n')],
                $template
            );
            $urls[] = $this->applyQuery($url, $monthQuery);
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveStartMonth(array $config, string $timezone): Carbon
    {
        $startMonth = Arr::get($config, 'start_month');

        if (is_string($startMonth)) {
            $startMonth = trim($startMonth);

            if ($startMonth === '' || in_array(strtolower($startMonth), ['current', 'now', 'today'], true)) {
                return Carbon::now($timezone)->startOfMonth();
            }

            try {
                return Carbon::parse($startMonth, $timezone)->startOfMonth();
            } catch (Throwable) {
                return Carbon::now($timezone)->startOfMonth();
            }
        }

        if ($startMonth instanceof Carbon) {
            return $startMonth->copy()->startOfMonth();
        }

        return Carbon::now($timezone)->startOfMonth();
    }

    /**
     * @return array<string, string>
     */
    private function normalizeMonthQuery(mixed $query): array
    {
        if (! is_array($query)) {
            return [];
        }

        $normalized = [];

        foreach ($query as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } else {
                $value = (string) $value;
            }

            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function applyQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $existing = $this->extractQueryParams($url);
        $base = $this->stripQueryFromUrl($url);
        $merged = array_merge($existing, $query);

        return $merged === [] ? $base : $base.'?'.http_build_query($merged);
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

    /**
     * @return array<string, string>
     */
    private function extractQueryParams(string $url): array
    {
        $queryString = parse_url($url, PHP_URL_QUERY);

        if (! is_string($queryString) || $queryString === '') {
            return [];
        }

        $params = [];
        parse_str($queryString, $params);

        return array_map(
            fn (mixed $value) => is_array($value) ? implode(',', $value) : (string) $value,
            $params
        );
    }

    private function stripQueryFromUrl(string $url): string
    {
        $base = strtok($url, '?');

        return $base !== false ? $base : $url;
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

    private function normalizeAllDay(mixed $explicit, bool $startAllDay, bool $endAllDay): bool
    {
        if (is_bool($explicit)) {
            return $explicit;
        }

        if (is_string($explicit)) {
            return in_array(strtolower($explicit), ['true', '1', 'yes'], true);
        }

        return $startAllDay || $endAllDay;
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

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }
}
