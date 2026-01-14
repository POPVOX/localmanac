<?php

namespace App\Services\Ingestion\Fetchers\HtmlProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class WichitaChamberEventsProfile extends AbstractHtmlProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'wichita_chamber_events';
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
            throw new InvalidArgumentException('Failed to fetch calendar listing');
        }

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
}
