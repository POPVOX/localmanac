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
            $pubDate = $this->stringValue($item->pubDate ?? ($item->updated ?? ($item->published ?? '')));
            $guid = $this->stringValue($item->guid ?? ($item->id ?? ''));
            $calendarDate = $this->calendarEventField($item, 'EventDates');
            $calendarTime = $this->calendarEventField($item, 'EventTimes');
            $calendarLocation = $this->normalizeCalendarText($this->calendarEventField($item, 'Location'));
            $structured = $this->structuredEventFields($item);

            $dateResult = $this->extractDateFromConfig($dateConfig, $title, $description, $timezone);

            if (! $dateResult && $calendarDate !== '') {
                $dateResult = $this->dateParser->parse($calendarDate, $calendarTime, $timezone);
            }

            if (! $dateResult) {
                $dateResult = $this->parseStructuredEventDate($structured, $timezone);
            }

            if (! $dateResult) {
                $dateResult = $this->extractLabelledDate($title.' '.$description, $timezone);
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
                locationName: $calendarLocation !== '' ? $calendarLocation : ($structured['location'] ?: null),
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
                    'structured_event_fields' => $structured,
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

    /**
     * Read common event extensions without requiring a vendor-specific namespace.
     * This covers feeds such as Eventbrite/WordPress calendar RSS and Google-style
     * Atom feeds that expose gd:when and gd:where attributes.
     *
     * @return array{start: string, end: string, date: string, time: string, end_time: string, location: string}
     */
    private function structuredEventFields(SimpleXMLElement $item): array
    {
        $fields = [
            'start' => '',
            'end' => '',
            'date' => '',
            'time' => '',
            'end_time' => '',
            'location' => '',
        ];

        foreach ($item->xpath('./*') ?: [] as $node) {
            if (! $node instanceof SimpleXMLElement) {
                continue;
            }

            $name = mb_strtolower($node->getName());
            $value = $this->normalizeCalendarText($this->stringValue($node));
            $attributes = $node->attributes();

            if ($name === 'when' && $attributes) {
                $fields['start'] = $fields['start'] ?: $this->stringValue($attributes['startTime'] ?? '');
                $fields['end'] = $fields['end'] ?: $this->stringValue($attributes['endTime'] ?? '');
            }

            if ($name === 'where' && $attributes) {
                $fields['location'] = $fields['location'] ?: $this->normalizeCalendarText(
                    $this->stringValue($attributes['valueString'] ?? '')
                );
            }

            if (in_array($name, ['startdate', 'startdatetime', 'dtstart', 'start'], true)) {
                $fields['start'] = $fields['start'] ?: $value;
            } elseif (in_array($name, ['enddate', 'enddatetime', 'dtend', 'end'], true)) {
                $fields['end'] = $fields['end'] ?: $value;
            } elseif (in_array($name, ['eventdate', 'meetingdate'], true)) {
                $fields['date'] = $fields['date'] ?: $value;
            } elseif (in_array($name, ['eventtime', 'eventtimes', 'meetingtime'], true)) {
                $fields['time'] = $fields['time'] ?: $value;
            } elseif ($name === 'starttime') {
                if ($this->containsDate($value)) {
                    $fields['start'] = $fields['start'] ?: $value;
                } else {
                    $fields['time'] = $fields['time'] ?: $value;
                }
            } elseif ($name === 'endtime') {
                if ($this->containsDate($value)) {
                    $fields['end'] = $fields['end'] ?: $value;
                } else {
                    $fields['end_time'] = $fields['end_time'] ?: $value;
                }
            } elseif (in_array($name, ['location', 'venue', 'where'], true)) {
                $fields['location'] = $fields['location'] ?: $value;
            }
        }

        return $fields;
    }

    /**
     * @param  array{start: string, end: string, date: string, time: string, end_time: string, location: string}  $fields
     * @return array{starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}|null
     */
    private function parseStructuredEventDate(array $fields, string $timezone): ?array
    {
        $date = $fields['start'] ?: $fields['date'];

        if ($date === '') {
            return null;
        }

        $result = $fields['time'] !== ''
            ? $this->dateParser->parse($date, $fields['time'], $timezone)
            : $this->dateParser->parseIso($date, $timezone);

        if (! $result || ! $result['starts_at']) {
            return null;
        }

        $endResult = null;

        if ($fields['end'] !== '') {
            $endResult = $this->dateParser->parseIso($fields['end'], $timezone);
        } elseif ($fields['end_time'] !== '') {
            $endResult = $this->dateParser->parse($date, $fields['end_time'], $timezone);
        }

        if ($endResult && $endResult['starts_at']) {
            $result['ends_at'] = $endResult['starts_at'];
        }

        return $result;
    }

    /**
     * Extract conservative, explicitly labelled event dates from feed text. The
     * publication date remains a last-resort fallback for legacy sources.
     *
     * @return array{starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}|null
     */
    private function extractLabelledDate(string $value, string $timezone): ?array
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $date = '(?:(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?,?\s+)?(?:[A-Z][a-z]+\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}|\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|\d{4}-\d{2}-\d{2})';
        $time = '(?:\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)?(?:\s*(?:-|to|–|—)\s*\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)?)?)';
        $pattern = '/\b(?:(?:event|meeting)\s*)?date\s*:?\s*(?<date>'.$date.')(?:\s*(?:at|,|\|)?\s*(?<time>'.$time.'))?|\b(?:when|starts?)\s*:?\s*(?<date_alt>'.$date.')(?:\s*(?:at|,|\|)?\s*(?<time_alt>'.$time.'))?/iu';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return $this->dateParser->parse(
            ($matches['date'] ?? '') ?: ($matches['date_alt'] ?? ''),
            ($matches['time'] ?? '') ?: ($matches['time_alt'] ?? ''),
            $timezone,
        );
    }

    private function containsDate(string $value): bool
    {
        return preg_match('/\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|[A-Z][a-z]+\s+\d{1,2})\b/i', $value) === 1;
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
