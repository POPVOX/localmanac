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
use Symfony\Component\DomCrawler\Crawler;

class HtmlCalendarFetcher implements EventSourceFetcher
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
        if ($source->source_type !== 'html') {
            throw new InvalidArgumentException('EventSource type must be html');
        }

        $sourceUrl = $source->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $config = $source->config ?? [];
        $profile = Arr::get($config, 'profile');

        if ($profile === 'wichita_chamber_events') {
            return $this->fetchWichitaChamberEvents($source, $config);
        }

        $listConfig = Arr::get($config, 'list', []);
        $detailConfig = Arr::get($config, 'detail', []);

        $itemSelector = Arr::get($listConfig, 'item_selector');

        if (! $itemSelector) {
            throw new InvalidArgumentException('EventSource list.item_selector is required');
        }

        $response = Http::timeout(15)->retry(2, 250)->get($sourceUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch calendar listing');
        }

        $timezone = Arr::get($config, 'timezone') ?? $source->city?->timezone ?? 'UTC';
        $maxItems = (int) Arr::get($listConfig, 'max_items', 50);

        $crawler = new Crawler($response->body(), $sourceUrl);
        $items = [];
        $detailFetches = 0;
        $maxDetailFetches = (int) Arr::get($detailConfig, 'max_detail_fetches', 15);
        $detailEnabled = (bool) Arr::get($detailConfig, 'enabled', false);

        foreach ($crawler->filter($itemSelector) as $node) {
            $itemCrawler = new Crawler($node, $sourceUrl);

            $title = $this->textFor($itemCrawler, Arr::get($listConfig, 'title_selector'));
            $dateText = $this->textFor($itemCrawler, Arr::get($listConfig, 'date_selector'));
            $timeText = $this->textFor($itemCrawler, Arr::get($listConfig, 'time_selector'));
            $location = $this->textFor($itemCrawler, Arr::get($listConfig, 'location_selector'));
            $link = $this->attrFor(
                $itemCrawler,
                Arr::get($listConfig, 'link_selector'),
                Arr::get($listConfig, 'link_attr', 'href')
            );
            $datetime = $this->attrFor(
                $itemCrawler,
                Arr::get($listConfig, 'datetime_selector', Arr::get($listConfig, 'time_selector')),
                Arr::get($listConfig, 'datetime_attr', 'datetime')
            );

            $eventUrl = $this->normalizer->normalizeUrl($link, $sourceUrl);

            $dateResult = $datetime !== ''
                ? $this->dateParser->parseIso($datetime, $timezone)
                : $this->dateParser->parse($dateText, $timeText, $timezone);

            $detailPayload = [];
            $description = null;

            if ($detailEnabled && $eventUrl && $detailFetches < $maxDetailFetches) {
                $detail = $this->fetchDetail($eventUrl, $detailConfig, $timezone);

                if ($detail) {
                    $detailFetches++;
                    $detailPayload = $detail['payload'];
                    $title = $title !== '' ? $title : $detail['title'];
                    $location = $location !== '' ? $location : $detail['location'];
                    $description = $detail['description'] ?: $description;
                    $eventUrl = $detail['event_url'] ?: $eventUrl;

                    if ($detail['date_result']) {
                        $dateResult = $detail['date_result'];
                    }
                }
            }

            if (! $dateResult || ! $dateResult['starts_at']) {
                continue;
            }

            $items[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $dateResult['starts_at'],
                endsAt: $dateResult['ends_at'] ?? null,
                allDay: $dateResult['all_day'],
                locationName: $location !== '' ? $location : null,
                locationAddress: null,
                description: $description,
                eventUrl: $eventUrl,
                externalId: null,
                sourceUrl: $eventUrl,
                rawPayload: [
                    'list' => [
                        'title' => $title,
                        'date' => $dateText,
                        'time' => $timeText,
                        'location' => $location,
                        'link' => $link,
                        'datetime' => $datetime,
                        'html' => Arr::get($listConfig, 'include_html') ? $itemCrawler->html() : null,
                    ],
                    'detail' => $detailPayload,
                ],
            );

            if ($maxItems > 0 && count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, EventDTO>
     */
    private function fetchWichitaChamberEvents(EventSource $source, array $config): array
    {
        $sourceUrl = $source->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $response = Http::timeout(15)->retry(2, 250)->get($sourceUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch calendar listing');
        }

        $timezone = Arr::get($config, 'timezone') ?? $source->city?->timezone ?? 'UTC';
        $maxItems = (int) Arr::get($config, 'list.max_items', 50);

        $crawler = new Crawler($response->body(), $sourceUrl);
        $items = [];

        foreach ($crawler->filter('.event') as $node) {
            $itemCrawler = new Crawler($node, $sourceUrl);

            $title = $this->textFor($itemCrawler, '.name a');
            $link = $this->attrFor($itemCrawler, '.name a', 'href');
            $location = $this->textFor($itemCrawler, '.meta .location');
            $metaText = $this->textFor($itemCrawler, '.meta');
            $year = trim((string) $itemCrawler->attr('data-year'));
            $description = $this->textFor($itemCrawler, '.description .description_short');

            [$dateText, $timeText] = $this->parseWichitaChamberMeta($metaText, $location, $year);

            $dateResult = $this->dateParser->parse($dateText, $timeText, $timezone);

            if (! $dateResult || ! $dateResult['starts_at']) {
                continue;
            }

            $eventUrl = $this->normalizer->normalizeUrl($link, $sourceUrl);
            $sourceHash = $this->buildWichitaChamberSourceHash($eventUrl, $dateResult['starts_at'], $title);

            $items[] = new EventDTO(
                title: $title !== '' ? $title : 'Untitled event',
                startsAt: $dateResult['starts_at'],
                endsAt: $dateResult['ends_at'] ?? null,
                allDay: $dateResult['all_day'],
                locationName: $location !== '' ? $location : null,
                locationAddress: null,
                description: $description !== '' ? $description : null,
                eventUrl: $eventUrl,
                externalId: null,
                sourceUrl: $eventUrl,
                sourceHash: $sourceHash,
                rawPayload: [
                    'list' => [
                        'title' => $title,
                        'meta' => $metaText,
                        'date' => $dateText,
                        'time' => $timeText,
                        'location' => $location,
                        'link' => $link,
                        'year' => $year,
                        'description' => $description,
                    ],
                ],
            );

            if ($maxItems > 0 && count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{title: string, location: string, description: ?string, event_url: ?string, date_result: ?array, payload: array<string, mixed>}|null
     */
    private function fetchDetail(string $url, array $config, string $timezone): ?array
    {
        $response = Http::timeout(15)->retry(2, 250)->get($url);

        if (! $response->successful()) {
            return null;
        }

        $crawler = new Crawler($response->body(), $url);
        $title = $this->textFor($crawler, Arr::get($config, 'title_selector'));
        $dateText = $this->textFor($crawler, Arr::get($config, 'date_selector'));
        $timeText = $this->textFor($crawler, Arr::get($config, 'time_selector'));
        $location = $this->textFor($crawler, Arr::get($config, 'location_selector'));
        $description = $this->textFor($crawler, Arr::get($config, 'description_selector'));
        $eventUrl = $this->attrFor(
            $crawler,
            Arr::get($config, 'link_selector'),
            Arr::get($config, 'link_attr', 'href')
        );
        $datetime = $this->attrFor(
            $crawler,
            Arr::get($config, 'datetime_selector', Arr::get($config, 'time_selector')),
            Arr::get($config, 'datetime_attr', 'datetime')
        );

        $dateResult = $datetime !== ''
            ? $this->dateParser->parseIso($datetime, $timezone)
            : $this->dateParser->parse($dateText, $timeText, $timezone);

        return [
            'title' => $title,
            'location' => $location,
            'description' => $description !== '' ? $description : null,
            'event_url' => $eventUrl !== '' ? $this->normalizer->normalizeUrl($eventUrl, $url) : null,
            'date_result' => $dateResult,
            'payload' => [
                'title' => $title,
                'date' => $dateText,
                'time' => $timeText,
                'location' => $location,
                'description' => $description,
                'event_url' => $eventUrl,
                'datetime' => $datetime,
                'html' => Arr::get($config, 'include_html') ? $crawler->html() : null,
            ],
        ];
    }

    private function textFor(Crawler $crawler, ?string $selector): string
    {
        if (! $selector) {
            return '';
        }

        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return '';
        }

        return trim($node->first()->text(''));
    }

    private function attrFor(Crawler $crawler, ?string $selector, string $attr): string
    {
        if (! $selector) {
            return '';
        }

        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return '';
        }

        return trim((string) $node->first()->attr($attr));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseWichitaChamberMeta(string $metaText, string $location, string $year): array
    {
        $metaText = $this->normalizeWhitespace($metaText);

        if ($location !== '') {
            $metaText = preg_replace('/\s*-?\s*'.preg_quote($location, '/').'\s*$/', '', $metaText) ?? $metaText;
        }

        $metaText = rtrim($metaText, "- \t\n\r\0\x0B");
        $metaText = trim($metaText);

        $dateText = $metaText;
        $timeText = '';

        if (preg_match('/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/i', $metaText, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $matches[0][1];
            $dateText = trim(substr($metaText, 0, $offset));
            $timeText = trim(substr($metaText, $offset));
        }

        $dateText = rtrim($dateText, ',- ');

        if ($year !== '' && preg_match('/\b\d{4}\b/', $dateText) !== 1) {
            $dateText = trim($dateText);
            $dateText = $dateText !== '' ? "{$dateText} {$year}" : $year;
        }

        $timeText = trim($timeText, '- ');

        return [$dateText, $timeText];
    }

    private function buildWichitaChamberSourceHash(?string $eventUrl, ?Carbon $startsAt, string $title): ?string
    {
        if (! $eventUrl) {
            return null;
        }

        if ($startsAt) {
            return sha1($eventUrl.'|'.$startsAt->toIso8601String());
        }

        return sha1($eventUrl.'|'.$title);
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
