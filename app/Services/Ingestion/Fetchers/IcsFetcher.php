<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\EventSource;
use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\EventNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class IcsFetcher implements EventSourceFetcher
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
        if ($source->source_type !== 'ics') {
            throw new InvalidArgumentException('EventSource type must be ics');
        }

        $sourceUrl = $source->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $response = Http::timeout(15)->retry(2, 250)->get($sourceUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch ICS feed');
        }

        $timezone = Arr::get($source->config, 'timezone') ?? $source->city?->timezone ?? 'UTC';

        return $this->parseIcs($response->body(), $timezone, $sourceUrl);
    }

    /**
     * @return array<int, EventDTO>
     */
    private function parseIcs(string $body, string $timezone, string $baseUrl): array
    {
        $lines = $this->unfoldLines($body);
        $items = [];
        $current = [];
        $inEvent = false;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                $inEvent = true;

                continue;
            }

            if ($line === 'END:VEVENT') {
                if ($inEvent) {
                    $dto = $this->mapEvent($current, $timezone, $baseUrl);

                    if ($dto) {
                        $items[] = $dto;
                    }
                }

                $current = [];
                $inEvent = false;

                continue;
            }

            if (! $inEvent) {
                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$rawKey, $value] = $parts;
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $property = strtoupper((string) strtok($rawKey, ';'));
            $params = $this->parseParams($rawKey);

            $current[$property][] = [
                'value' => $value,
                'params' => $params,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, array<int, array{value: string, params: array<string, string>}>>  $event
     */
    private function mapEvent(array $event, string $timezone, string $baseUrl): ?EventDTO
    {
        $summary = $this->firstValue($event['SUMMARY'] ?? []);
        $dtStart = $this->firstEntry($event['DTSTART'] ?? []);

        if (! $summary || ! $dtStart) {
            return null;
        }

        $dtEnd = $this->firstEntry($event['DTEND'] ?? []);
        $location = $this->firstValue($event['LOCATION'] ?? []);
        $description = $this->firstValue($event['DESCRIPTION'] ?? []);
        $url = $this->firstValue($event['URL'] ?? []);
        $uid = $this->firstValue($event['UID'] ?? []);

        $tzid = $dtStart['params']['TZID'] ?? null;
        $startResult = $this->parseIcsDate($dtStart['value'], $tzid, $timezone);

        if (! $startResult) {
            return null;
        }

        $endResult = null;
        $allDay = $startResult['all_day'];

        if ($dtEnd) {
            $endTzid = $dtEnd['params']['TZID'] ?? $tzid;
            $endResult = $this->parseIcsDate($dtEnd['value'], $endTzid, $timezone);
        }

        $startsAt = $startResult['starts_at'];
        $endsAt = $endResult['starts_at'] ?? null;
        $allDay = $allDay || ($endResult['all_day'] ?? false);

        [$eventUrl, $description] = $this->resolveEventUrl($url, $description, $baseUrl);
        [$location, $description] = $this->normalizeLocation($location, $description);

        return new EventDTO(
            title: $summary,
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: $allDay,
            locationName: $location,
            locationAddress: null,
            description: $description,
            eventUrl: $eventUrl,
            externalId: $uid,
            sourceUrl: $eventUrl,
            rawPayload: [
                'summary' => $summary,
                'dtstart' => $dtStart,
                'dtend' => $dtEnd,
                'location' => $location,
                'description' => $description,
                'url' => $url,
                'uid' => $uid,
            ],
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveEventUrl(?string $url, ?string $description, string $baseUrl): array
    {
        $eventUrl = $this->normalizer->normalizeUrl($url, $baseUrl);
        $descriptionUrl = $this->extractUrlCandidate($description, $baseUrl);

        if ($descriptionUrl) {
            if (! $eventUrl || $this->urlsMatch($eventUrl, $baseUrl) || $eventUrl === $descriptionUrl) {
                return [$descriptionUrl, null];
            }
        }

        return [$eventUrl, $description];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizeLocation(?string $location, ?string $description): array
    {
        if ($location === null) {
            return [null, $description];
        }

        $location = trim($location);

        if ($location === '') {
            return [null, $description];
        }

        if ($this->containsHtml($location)) {
            return [null, $this->mergeDescription($description, $location)];
        }

        return [$location, $description];
    }

    private function mergeDescription(?string $description, string $location): string
    {
        if ($description === null || trim($description) === '') {
            return $location;
        }

        return trim($description)."\n\n".$location;
    }

    private function containsHtml(string $value): bool
    {
        return preg_match('/<[^>]+>/', $value) === 1;
    }

    private function extractUrlCandidate(?string $value, string $baseUrl): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || preg_match('/\s/', $value) === 1) {
            return null;
        }

        if (
            str_starts_with($value, 'http://')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, '//')
            || str_starts_with($value, '/')
        ) {
            return $this->normalizer->normalizeUrl($value, $baseUrl);
        }

        return null;
    }

    private function urlsMatch(string $left, string $right): bool
    {
        $leftParts = parse_url($left);
        $rightParts = parse_url($right);

        if (! $leftParts || ! $rightParts) {
            return false;
        }

        if (($leftParts['scheme'] ?? null) !== ($rightParts['scheme'] ?? null)) {
            return false;
        }

        if (($leftParts['host'] ?? null) !== ($rightParts['host'] ?? null)) {
            return false;
        }

        if (($leftParts['path'] ?? null) !== ($rightParts['path'] ?? null)) {
            return false;
        }

        return $this->normalizeQuery($leftParts['query'] ?? '') === $this->normalizeQuery($rightParts['query'] ?? '');
    }

    private function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        ksort($params);

        return http_build_query($params);
    }

    /**
     * @return array{starts_at: Carbon, all_day: bool}|null
     */
    private function parseIcsDate(string $value, ?string $tzid, string $defaultTz): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timezone = $tzid ?: $defaultTz;
        $isDateOnly = preg_match('/^\d{8}$/', $value) === 1;

        if ($isDateOnly) {
            $date = Carbon::createFromFormat('Ymd', $value, $timezone);

            return [
                'starts_at' => $date->startOfDay(),
                'all_day' => true,
            ];
        }

        if (str_ends_with($value, 'Z') && preg_match('/^\d{8}T\d{6}Z$/', $value) === 1) {
            $date = Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC')->setTimezone($timezone);

            return [
                'starts_at' => $date,
                'all_day' => false,
            ];
        }

        if (preg_match('/^\d{8}T\d{6}$/', $value) === 1) {
            $date = Carbon::createFromFormat('Ymd\THis', $value, $timezone);

            return [
                'starts_at' => $date,
                'all_day' => false,
            ];
        }

        $parsed = $this->dateParser->parseIso($value, $timezone);

        if (! $parsed || ! $parsed['starts_at']) {
            return null;
        }

        return [
            'starts_at' => $parsed['starts_at'],
            'all_day' => $parsed['all_day'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function unfoldLines(string $body): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^[ \t]/', $line) === 1 && $unfolded !== []) {
                $unfolded[count($unfolded) - 1] .= ltrim($line);

                continue;
            }

            $unfolded[] = trim($line);
        }

        return $unfolded;
    }

    /**
     * @return array<string, string>
     */
    private function parseParams(string $rawKey): array
    {
        $parts = explode(';', $rawKey);
        array_shift($parts);

        $params = [];

        foreach ($parts as $part) {
            $pair = explode('=', $part, 2);

            if (count($pair) === 2) {
                $params[strtoupper($pair[0])] = $pair[1];
            }
        }

        return $params;
    }

    /**
     * @param  array<int, array{value: string, params: array<string, string>}>  $entries
     */
    private function firstEntry(array $entries): ?array
    {
        return $entries[0] ?? null;
    }

    /**
     * @param  array<int, array{value: string, params: array<string, string>}>  $entries
     */
    private function firstValue(array $entries): ?string
    {
        return $entries[0]['value'] ?? null;
    }
}
