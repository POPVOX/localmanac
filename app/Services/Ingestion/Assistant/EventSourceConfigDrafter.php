<?php

namespace App\Services\Ingestion\Assistant;

use Illuminate\Support\Arr;
use Symfony\Component\DomCrawler\Crawler;

class EventSourceConfigDrafter
{
    /**
     * @return array{config: array<string, mixed>, confidence: float, warnings: array<int, string>}
     */
    public function draft(string $type, string $sourceUrl, string $body): array
    {
        return match ($type) {
            'ics' => [
                'config' => ['timezone' => null],
                'confidence' => 0.99,
                'warnings' => [],
            ],
            'rss' => [
                'config' => ['timezone' => null],
                'confidence' => 0.86,
                'warnings' => ['Event dates will be read from the feed. Confirm the sample dates before saving.'],
            ],
            'json', 'json_api' => $this->draftJson($body),
            'html' => $this->draftHtml($sourceUrl, $body),
            default => [
                'config' => [],
                'confidence' => 0.2,
                'warnings' => ['This event source format is not recognized.'],
            ],
        };
    }

    /**
     * @return array{config: array<string, mixed>, confidence: float, warnings: array<int, string>}
     */
    private function draftJson(string $body): array
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return [
                'config' => ['json' => ['root_path' => '']],
                'confidence' => 0.35,
                'warnings' => ['The endpoint did not return valid JSON during discovery.'],
            ];
        }

        [$listPath, $items] = $this->findJsonItems($payload);
        $first = is_array($items[0] ?? null) ? $items[0] : [];
        $mapping = $this->inferJsonMapping($first);

        $json = [
            'root_path' => $listPath,
        ];

        if ($mapping !== []) {
            $json['mapping'] = $mapping;
        }

        return [
            'config' => ['json' => $json],
            'confidence' => $first !== [] && isset($mapping['starts_at']) ? 0.9 : 0.58,
            'warnings' => isset($mapping['starts_at'])
                ? []
                : ['A start-date field was not obvious. Review the JSON mapping in Advanced.'],
        ];
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function findJsonItems(array $payload): array
    {
        if (array_is_list($payload)) {
            return ['', $payload];
        }

        foreach (['events', 'items', 'results', 'data', 'docs'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_array($value) && array_is_list($value)) {
                return [$key, $value];
            }

            if (is_array($value)) {
                foreach (['events', 'items', 'results', 'docs'] as $childKey) {
                    $children = $value[$childKey] ?? null;

                    if (is_array($children) && array_is_list($children)) {
                        return ["{$key}.{$childKey}", $children];
                    }
                }
            }
        }

        return ['', []];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function inferJsonMapping(array $item): array
    {
        $aliases = [
            'title' => ['title', 'name', 'summary'],
            'starts_at' => ['starts_at', 'start.dateTime', 'start.date', 'start_time', 'start_date', 'startDate', 'startDateTime', 'dtstart', 'start'],
            'ends_at' => ['ends_at', 'end.dateTime', 'end.date', 'end_time', 'end_date', 'endDate', 'endDateTime', 'dtend', 'end'],
            'location_name' => ['location_name', 'location.name', 'venue.name', 'location', 'venue'],
            'location_address' => ['location_address', 'location.address', 'venue.address'],
            'description' => ['description', 'details', 'body'],
            'event_url' => ['event_url', 'url', 'link'],
            'external_id' => ['external_id', 'id', 'uid'],
            'all_day' => ['all_day', 'allDay'],
        ];

        $mapping = [];

        foreach ($aliases as $target => $candidates) {
            foreach ($candidates as $candidate) {
                $value = Arr::get($item, $candidate);

                if (Arr::has($item, $candidate) && ! is_array($value) && ! is_object($value)) {
                    $mapping[$target] = $candidate;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @return array{config: array<string, mixed>, confidence: float, warnings: array<int, string>}
     */
    private function draftHtml(string $sourceUrl, string $body): array
    {
        $crawler = new Crawler($body, $sourceUrl);
        $itemSelector = $this->firstSelectorWithCount($crawler, [
            '[itemtype*="schema.org/Event"]',
            '[itemtype*="Event"]',
            '.event-list .event',
            '.events-list .event',
            '.events-list li',
            '.calendar-events .event',
            '.calendar-event',
            'article.event',
            '.event',
        ], 2) ?? '.event';

        $firstItem = $this->firstNode($crawler, $itemSelector);
        $titleSelector = $this->firstSelectorWithCount($firstItem, [
            '[itemprop="name"]',
            '.event-title a',
            '.event-title',
            'h2 a',
            'h3 a',
            'h2',
            'h3',
            'a',
        ], 1) ?? 'a';
        $datetimeSelector = $this->firstSelectorWithCount($firstItem, [
            'time[datetime]',
            '[itemprop="startDate"][content]',
            '[data-start]',
        ], 1);
        $dateSelector = $this->firstSelectorWithCount($firstItem, [
            '[itemprop="startDate"]',
            '.event-date',
            '.date',
            'time',
        ], 1) ?? 'time';
        $timeSelector = $this->firstSelectorWithCount($firstItem, [
            '.event-time',
            '.time',
            'time',
        ], 1);
        $locationSelector = $this->firstSelectorWithCount($firstItem, [
            '[itemprop="location"]',
            '.event-location',
            '.location',
            '.venue',
        ], 1);
        $linkSelector = $this->firstSelectorWithCount($firstItem, [
            '.event-title a[href]',
            'h2 a[href]',
            'h3 a[href]',
            'a[href]',
        ], 1) ?? 'a[href]';

        $list = array_filter([
            'item_selector' => $itemSelector,
            'title_selector' => $titleSelector,
            'date_selector' => $dateSelector,
            'time_selector' => $timeSelector,
            'location_selector' => $locationSelector,
            'link_selector' => $linkSelector,
            'link_attr' => 'href',
            'datetime_selector' => $datetimeSelector,
            'datetime_attr' => $datetimeSelector ? ($this->selectorUsesContent($datetimeSelector) ? 'content' : ($this->selectorUsesDataStart($datetimeSelector) ? 'data-start' : 'datetime')) : null,
            'max_items' => 50,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $matchedItems = $this->selectorCount($crawler, $itemSelector);

        return [
            'config' => [
                'profile' => 'generic_html_list',
                'timezone' => null,
                'list' => $list,
                'detail' => ['enabled' => false],
            ],
            'confidence' => $matchedItems >= 2 && ($datetimeSelector || $dateSelector !== 'time') ? 0.82 : 0.52,
            'warnings' => $matchedItems >= 2
                ? []
                : ['The calendar item wrapper was not obvious. Confirm the preview before saving.'],
        ];
    }

    private function firstNode(Crawler $crawler, string $selector): Crawler
    {
        try {
            $nodes = $crawler->filter($selector);

            return $nodes->count() > 0 ? $nodes->eq(0) : $crawler;
        } catch (\Throwable) {
            return $crawler;
        }
    }

    /**
     * @param  list<string>  $selectors
     */
    private function firstSelectorWithCount(Crawler $crawler, array $selectors, int $minimum): ?string
    {
        foreach ($selectors as $selector) {
            if ($this->selectorCount($crawler, $selector) >= $minimum) {
                return $selector;
            }
        }

        return null;
    }

    private function selectorCount(Crawler $crawler, string $selector): int
    {
        try {
            return $crawler->filter($selector)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function selectorUsesContent(string $selector): bool
    {
        return str_contains($selector, '[content]');
    }

    private function selectorUsesDataStart(string $selector): bool
    {
        return str_contains($selector, '[data-start]');
    }
}
