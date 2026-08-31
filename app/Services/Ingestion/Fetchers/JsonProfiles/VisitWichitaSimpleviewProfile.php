<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\EventNormalizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class VisitWichitaSimpleviewProfile extends AbstractJsonProfile
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 20;

    public function __construct(
        CalendarDateParser $dateParser,
        EventNormalizer $normalizer,
        private readonly ?VisitWichitaTokenResolver $tokenResolver = null,
    ) {
        parent::__construct($dateParser, $normalizer);
    }

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
        $sourceConfig = is_array($source->config) ? $source->config : [];
        $request = $this->buildVisitWichitaRequest($source->source_url ?? '', $sourceConfig, $timezone);
        $requestUrl = $request['request_url'];
        $jsonPayload = $request['json_payload'];
        $storedToken = $request['token'];

        if ($storedToken !== '') {
            $result = $this->requestVisitWichitaPayloads($requestUrl, $jsonPayload, $storedToken, $config);

            if ($result['failure'] === null) {
                return $result['payloads'];
            }

            if (! $this->isInvalidCredentialResponse($result['failure'])) {
                throw new InvalidArgumentException($this->formatFailedFetchMessage($result['failure']));
            }
        }

        $resolvedToken = $this->resolveVisitWichitaToken($sourceConfig);
        $result = $this->requestVisitWichitaPayloads($requestUrl, $jsonPayload, $resolvedToken, $config);

        if ($result['failure'] !== null) {
            throw new InvalidArgumentException(
                'Visit Wichita token refresh retry failed. '.$this->formatFailedFetchMessage($result['failure'])
            );
        }

        $this->persistResolvedToken($source, $sourceConfig, $resolvedToken);

        return $result['payloads'];
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
     * @return array{request_url: string, json_payload: string, token: string}
     */
    private function buildVisitWichitaRequest(string $sourceUrl, array $sourceConfig, string $timezone): array
    {
        $existingQuery = $this->extractQueryParams($sourceUrl);
        $token = $this->stringValue(Arr::get($sourceConfig, 'auth.token'));

        if ($token === '' && isset($existingQuery['token'])) {
            $token = $this->stringValue($existingQuery['token']);
        }

        $jsonPayload = $this->normalizeVisitWichitaJsonPayload(
            $existingQuery['json']
                ?? Arr::get($sourceConfig, 'json.query')
                ?? Arr::get($sourceConfig, 'json.payload')
                ?? Arr::get($sourceConfig, 'auth.json'),
            $timezone
        );
        $baseUrl = $this->stripQueryFromUrl($sourceUrl);

        Log::debug('Visit Wichita Simpleview request prepared.', [
            'url' => $baseUrl,
            'token_present' => $token !== '',
        ]);

        return [
            'request_url' => $baseUrl,
            'json_payload' => $jsonPayload,
            'token' => $token,
        ];
    }

    private function requestVisitWichitaPayload(string $requestUrl, string $jsonPayload, string $token): Response
    {
        return Http::timeout(15)
            ->retry(2, 250, throw: false)
            ->withOptions([
                'query' => [
                    'json' => $jsonPayload,
                    'token' => $token,
                ],
            ])
            ->get($requestUrl);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *     payloads: array<int, array{request_url: string, payload: mixed}>,
     *     failure: Response|null
     * }
     */
    private function requestVisitWichitaPayloads(
        string $requestUrl,
        string $jsonPayload,
        string $token,
        array $config,
    ): array {
        try {
            $payload = json_decode($jsonPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Visit Wichita JSON query must be valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Visit Wichita JSON query must decode to an object.');
        }

        $configuredLimit = data_get($payload, 'options.limit', self::PAGE_SIZE);
        $pageSize = is_numeric($configuredLimit)
            ? max(1, min(self::PAGE_SIZE, (int) $configuredLimit))
            : self::PAGE_SIZE;
        $configuredSkip = data_get($payload, 'options.skip', 0);
        $initialSkip = is_numeric($configuredSkip) ? max(0, (int) $configuredSkip) : 0;
        $listPath = $this->resolveListPath($config);
        $payloads = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            data_set($payload, 'options.limit', $pageSize);
            data_set($payload, 'options.skip', $initialSkip + ($page * $pageSize));

            $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

            if (! is_string($encodedPayload)) {
                throw new InvalidArgumentException('Visit Wichita JSON query could not be encoded.');
            }

            $response = $this->requestVisitWichitaPayload($requestUrl, $encodedPayload, $token);

            if (! $response->successful()) {
                return [
                    'payloads' => [],
                    'failure' => $response,
                ];
            }

            $responsePayload = $response->json();
            $payloads[] = [
                'request_url' => $requestUrl,
                'payload' => $responsePayload,
            ];

            $items = $listPath === '' ? $responsePayload : data_get($responsePayload, $listPath, []);

            if (! is_array($items) || count($items) < $pageSize) {
                return [
                    'payloads' => $payloads,
                    'failure' => null,
                ];
            }
        }

        throw new InvalidArgumentException('Visit Wichita pagination exceeded the safety limit.');
    }

    private function isInvalidCredentialResponse(Response $response): bool
    {
        if ($response->status() !== 403) {
            return false;
        }

        return str_contains(strtolower($response->body()), 'invalid credentials');
    }

    /**
     * @param  array<string, mixed>  $sourceConfig
     */
    private function resolveVisitWichitaToken(array $sourceConfig): string
    {
        $tokenSourceUrl = $this->stringValue(Arr::get($sourceConfig, 'auth.token_source_url'));

        if ($tokenSourceUrl === '') {
            $tokenSourceUrl = (string) config(
                'services.visit_wichita.token_source_url',
                'https://www.visitwichita.com/events/?view=list&sort=date'
            );
        }

        $resolver = $this->tokenResolver ?? new VisitWichitaTokenResolver;
        $resolution = $resolver->resolve($tokenSourceUrl);
        $token = $this->stringValue($resolution['token'] ?? '');

        if ($token !== '') {
            return $token;
        }

        $message = $this->stringValue($resolution['error'] ?? '');

        if ($message === '') {
            $message = 'Unable to resolve a token from the Visit Wichita source page.';
        }

        throw new InvalidArgumentException('Visit Wichita token refresh failed: '.$message);
    }

    /**
     * @param  array<string, mixed>  $sourceConfig
     */
    private function persistResolvedToken(EventSource $source, array $sourceConfig, string $token): void
    {
        $storedToken = $this->stringValue(Arr::get($sourceConfig, 'auth.token'));

        if ($storedToken === $token) {
            return;
        }

        data_set($sourceConfig, 'auth.token', $token);

        $source->forceFill([
            'config' => $sourceConfig,
        ])->save();
    }

    private function formatFailedFetchMessage(Response $response): string
    {
        $status = $response->status();
        $body = trim(preg_replace('/\s+/', ' ', $response->body()) ?? '');

        if ($body === '') {
            return "Failed to fetch JSON feed (status {$status}).";
        }

        $snippet = substr($body, 0, 280);

        return "Failed to fetch JSON feed (status {$status}): {$snippet}";
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
                'limit' => self::PAGE_SIZE,
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
