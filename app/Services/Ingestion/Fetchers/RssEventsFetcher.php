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
use SimpleXMLElement;
use Throwable;

class RssEventsFetcher implements EventSourceFetcher
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
        if ($source->source_type !== 'rss') {
            throw new InvalidArgumentException('EventSource type must be rss');
        }

        $feedUrl = $source->source_url;

        if (! $feedUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $response = Http::timeout(15)->retry(2, 250)->get($feedUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch RSS feed');
        }

        $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $items = $this->extractItems($xml);
        $timezone = Arr::get($source->config, 'timezone') ?? $source->city?->timezone ?? 'UTC';
        $dateConfig = Arr::get($source->config, 'date_extraction', Arr::get($source->config, 'date')) ?? [];
        $results = [];

        foreach ($items as $item) {
            $title = $this->stringValue($item->title ?? '');
            $link = $this->extractLink($item);
            $description = $this->extractDescription($item);
            $pubDate = $this->stringValue($item->pubDate ?? ($item->updated ?? ''));
            $guid = $this->stringValue($item->guid ?? '');
            $calendarDate = $this->calendarEventField($item, 'EventDates');
            $calendarTime = $this->calendarEventField($item, 'EventTimes');
            $calendarLocation = $this->normalizeCalendarText($this->calendarEventField($item, 'Location'));

            $dateResult = $this->extractDateFromConfig($dateConfig, $title, $description, $timezone);

            if (! $dateResult && $calendarDate !== '') {
                $dateResult = $this->dateParser->parse($calendarDate, $calendarTime, $timezone);
            }

            if (! $dateResult && $pubDate !== '') {
                $dateResult = $this->fallbackPubDate($pubDate, $timezone);
            }

            if (! $dateResult || ! $dateResult['starts_at']) {
                continue;
            }

            $eventUrl = $this->normalizer->normalizeUrl($link, $feedUrl);
            $startsAt = $dateResult['starts_at'];
            $endsAt = $dateResult['ends_at'] ?? null;
            $allDay = $dateResult['all_day'];

            $results[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $startsAt,
                endsAt: $endsAt,
                allDay: $allDay,
                locationName: $calendarLocation !== '' ? $calendarLocation : null,
                locationAddress: null,
                description: $description ?: null,
                eventUrl: $eventUrl,
                externalId: $guid !== '' ? $guid : null,
                sourceUrl: $eventUrl,
                rawPayload: [
                    'title' => $title,
                    'link' => $link,
                    'description' => $description,
                    'pub_date' => $pubDate,
                    'guid' => $guid,
                    'event_dates' => $calendarDate,
                    'event_times' => $calendarTime,
                    'location' => $calendarLocation,
                ],
            );
        }

        return $results;
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    private function extractItems(SimpleXMLElement $xml): array
    {
        if (isset($xml->channel->item)) {
            return iterator_to_array($xml->channel->item, false);
        }

        if (isset($xml->entry)) {
            return iterator_to_array($xml->entry, false);
        }

        return [];
    }

    private function extractLink(SimpleXMLElement $item): string
    {
        if (isset($item->link)) {
            $link = $this->stringValue($item->link);

            if ($link !== '') {
                return $link;
            }

            $attributes = $item->link->attributes();

            if ($attributes && isset($attributes['href'])) {
                return (string) $attributes['href'];
            }
        }

        return '';
    }

    private function extractDescription(SimpleXMLElement $item): string
    {
        $description = $this->stringValue($item->description ?? ($item->summary ?? ''));

        if ($description !== '') {
            return $description;
        }

        $content = $item->children('http://purl.org/rss/1.0/modules/content/');
        $encoded = $this->stringValue($content->encoded ?? '');

        return $encoded;
    }

    private function calendarEventField(SimpleXMLElement $item, string $field): string
    {
        foreach ($item->getNamespaces(true) as $prefix => $namespace) {
            if (! str_contains(mb_strtolower($prefix.' '.$namespace), 'calendar')) {
                continue;
            }

            $children = $item->children($namespace);
            $value = $this->stringValue($children->{$field} ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeCalendarText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/<br\s*\/?\s*>/i', ', ', $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/(?<=[a-z])(?=[A-Z][a-z]+,\s*[A-Z]{2}\b)/u', ', ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}|null
     */
    private function extractDateFromConfig(array $config, string $title, string $description, string $timezone): ?array
    {
        $regex = $config['regex'] ?? null;
        $format = $config['format'] ?? null;
        $allDay = (bool) ($config['all_day'] ?? false);

        if (! $regex) {
            return null;
        }

        $haystack = trim($title.' '.$description);

        if ($haystack === '') {
            return null;
        }

        if (preg_match($regex, $haystack, $matches) !== 1) {
            return null;
        }

        $value = $matches['datetime'] ?? $matches[1] ?? $matches[0] ?? null;

        if (! $value) {
            return null;
        }

        $parsed = $this->parseDateValue($value, $format, $timezone);

        if (! $parsed) {
            return null;
        }

        return [
            'starts_at' => $allDay ? $parsed->copy()->startOfDay() : $parsed,
            'ends_at' => null,
            'all_day' => $allDay,
        ];
    }

    /**
     * @return array{starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}|null
     */
    private function fallbackPubDate(string $value, string $timezone): ?array
    {
        try {
            $parsed = Carbon::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }

        return [
            'starts_at' => $parsed->copy()->startOfDay(),
            'ends_at' => null,
            'all_day' => true,
        ];
    }

    private function parseDateValue(string $value, ?string $format, string $timezone): ?Carbon
    {
        try {
            if ($format) {
                return Carbon::createFromFormat($format, $value, $timezone);
            }

            return Carbon::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }
}
