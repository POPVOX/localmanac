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

        if (! array_is_list($items)) {
            $items = $items !== [] && collect($items)->every(fn (mixed $item): bool => is_array($item))
                ? array_values($items)
                : [$items];
        }

        return $this->mapGenericItems($items, $mapping, $config, $timezone, $requestUrl);
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<string, string>  $mapping
     * @param  array<string, mixed>  $config
     * @return array<int, EventDTO>
     */
    private function mapGenericItems(array $items, array $mapping, array $config, string $timezone, string $sourceUrl): array
    {
        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = (string) $this->getValue($item, $mapping, 'title', ['title', 'name']);
            $startsAtValue = $this->stringValue(
                $this->getValue($item, $mapping, 'starts_at', ['starts_at', 'start', 'start_date', 'date'])
            );
            $startTimeValue = $this->stringValue(
                $this->getValue($item, $mapping, 'start_time', ['start_time', 'startTime', 'time'])
            );
            $endsAtValue = $this->stringValue(
                $this->getValue($item, $mapping, 'ends_at', ['ends_at', 'end', 'end_date'])
            );
            $endTimeValue = $this->stringValue(
                $this->getValue($item, $mapping, 'end_time', ['end_time', 'endTime'])
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

            if ($eventUrl === '') {
                $eventUrl = $this->expandItemTemplate(
                    $this->stringValue(Arr::get($config, 'event_url_template')),
                    $item,
                );
            }

            $externalId = $this->stringValue(
                $this->getValue($item, $mapping, 'external_id', ['id', 'external_id', 'uid'])
            );
            $allDayValue = $this->getValue($item, $mapping, 'all_day', ['all_day']);

            $startResult = $this->parseDateAndOptionalTime($startsAtValue, $startTimeValue, $timezone);
            $endResult = ($endsAtValue !== '' || $endTimeValue !== '')
                ? $this->parseDateAndOptionalTime(
                    $endsAtValue !== '' ? $endsAtValue : $startsAtValue,
                    $endTimeValue,
                    $timezone,
                )
                : null;

            $startsAt = $startResult['starts_at'] ?? null;
            $endsAt = $endResult['starts_at'] ?? ($startResult['ends_at'] ?? null);

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
     * @return array{starts_at: ?\Illuminate\Support\Carbon, ends_at: ?\Illuminate\Support\Carbon, all_day: bool}|null
     */
    private function parseDateAndOptionalTime(string $date, string $time, string $timezone): ?array
    {
        if ($date === '' && $time === '') {
            return null;
        }

        return $time !== ''
            ? $this->dateParser->parse($date, $time, $timezone)
            : $this->dateParser->parseIso($date, $timezone);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function expandItemTemplate(string $template, array $item): string
    {
        if ($template === '') {
            return '';
        }

        return preg_replace_callback('/\{([^{}]+)\}/', function (array $matches) use ($item): string {
            $value = data_get($item, $matches[1]);

            return is_scalar($value) ? rawurlencode((string) $value) : '';
        }, $template) ?? '';
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
