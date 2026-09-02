<?php

namespace App\Services\Ingestion\Fetchers\HtmlProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class JsonLdEventsProfile extends AbstractHtmlProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'json_ld_events';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, EventDTO>
     */
    public function fetchAndMap(EventSource $source, array $config, string $timezone): array
    {
        $sourceUrl = $source->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $response = Http::timeout(15)->retry(2, 250)->get($sourceUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch calendar page');
        }

        $crawler = new Crawler($response->body(), $sourceUrl);
        $records = [];

        foreach ($crawler->filter('script[type*="ld+json"]') as $node) {
            $payload = json_decode(trim((string) $node->textContent), true);

            if (is_array($payload)) {
                $this->collectEvents($payload, $records);
            }
        }

        $events = [];
        $maxItems = max(1, (int) Arr::get($config, 'max_items', 100));

        foreach ($records as $record) {
            $startsAtValue = $this->scalarValue($record['startDate'] ?? null);
            $startResult = $this->dateParser->parseIso($startsAtValue, $timezone);

            if (! $startResult || ! $startResult['starts_at']) {
                continue;
            }

            $endResult = $this->dateParser->parseIso($this->scalarValue($record['endDate'] ?? null), $timezone);
            [$locationName, $locationAddress] = $this->location($record['location'] ?? null);
            $eventUrl = $this->normalizer->normalizeUrl(
                $this->scalarValue($record['url'] ?? ($record['@id'] ?? null)),
                $sourceUrl,
            );
            $externalId = $this->identifier($record);
            $title = $this->scalarValue($record['name'] ?? ($record['headline'] ?? null));
            $description = $this->scalarValue($record['description'] ?? null);

            $events[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $startResult['starts_at'],
                endsAt: $endResult['starts_at'] ?? null,
                allDay: $startResult['all_day'],
                locationName: $locationName,
                locationAddress: $locationAddress,
                description: $description !== '' ? $description : null,
                eventUrl: $eventUrl,
                externalId: $externalId,
                sourceUrl: $eventUrl ?? $sourceUrl,
                rawPayload: ['json_ld' => $record],
            );

            if (count($events) >= $maxItems) {
                break;
            }
        }

        return $events;
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @param  array<int, array<string, mixed>>  $events
     */
    private function collectEvents(array $value, array &$events, int $depth = 0): void
    {
        if ($depth > 10) {
            return;
        }

        $type = $value['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        $isEvent = collect($types)->contains(
            fn (mixed $candidate): bool => is_string($candidate) && mb_strtolower($candidate) === 'event'
        );

        if ($isEvent) {
            $events[] = $value;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectEvents($child, $events, $depth + 1);
            }
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function location(mixed $value): array
    {
        if (is_scalar($value)) {
            $name = trim((string) $value);

            return [$name !== '' ? $name : null, null];
        }

        if (! is_array($value)) {
            return [null, null];
        }

        $name = $this->scalarValue($value['name'] ?? null);
        $addressValue = $value['address'] ?? null;

        if (is_array($addressValue)) {
            $address = implode(', ', array_filter(array_map(
                fn (string $key): string => $this->scalarValue($addressValue[$key] ?? null),
                ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode']
            )));
        } else {
            $address = $this->scalarValue($addressValue);
        }

        return [
            $name !== '' ? $name : null,
            $address !== '' ? $address : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function identifier(array $record): ?string
    {
        $identifier = $record['identifier'] ?? ($record['@id'] ?? null);

        if (is_array($identifier)) {
            $identifier = $identifier['value'] ?? ($identifier['@id'] ?? null);
        }

        $value = $this->scalarValue($identifier);

        return $value !== '' ? $value : null;
    }

    private function scalarValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
