<?php

namespace App\Services\Ingestion\Assistant;

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
        $first = $this->representativeJsonRecord($items, $listPath);
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
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    private function findJsonItems(array $payload): array
    {
        $candidates = [];
        $this->collectJsonLists($payload, '', $candidates);

        usort($candidates, function (array $left, array $right): int {
            return [$right['score'], count($right['items']), -substr_count($right['path'], '.')]
                <=> [$left['score'], count($left['items']), -substr_count($left['path'], '.')];
        });

        $best = $candidates[0] ?? null;

        if (! is_array($best) && ! array_is_list($payload) && $this->jsonRecordScore($payload, '') > 0) {
            return ['', [$payload]];
        }

        return is_array($best)
            ? [$best['path'], $best['items']]
            : ['', []];
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return array<string, mixed>
     */
    private function representativeJsonRecord(array $items, string $path): array
    {
        $records = collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->take(10)
            ->values();

        return $records
            ->sortByDesc(fn (array $item): int => $this->jsonRecordScore($item, $path))
            ->first() ?? [];
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @param  array<int, array{path: string, items: array<int|string, mixed>, score: int}>  $candidates
     */
    private function collectJsonLists(array $value, string $path, array &$candidates, int $depth = 0): void
    {
        if ($depth > 7) {
            return;
        }

        if ($this->looksLikeRecordCollection($value)) {
            $first = collect($value)->first(fn (mixed $item): bool => is_array($item));
            $score = is_array($first) ? $this->jsonRecordScore($first, $path) : 0;

            if ($score > 0) {
                $candidates[] = [
                    'path' => $path,
                    'items' => $value,
                    'score' => $score,
                ];
            }

            if (array_is_list($value)) {
                return;
            }
        }

        foreach ($value as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            $childPath = $path === '' ? (string) $key : $path.'.'.$key;
            $this->collectJsonLists($child, $childPath, $candidates, $depth + 1);
        }
    }

    /**
     * @param  array<string|int, mixed>  $value
     */
    private function looksLikeRecordCollection(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        if (array_is_list($value)) {
            return collect($value)->contains(fn (mixed $item): bool => is_array($item));
        }

        return collect($value)->every(fn (mixed $item): bool => is_array($item));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function jsonRecordScore(array $item, string $path): int
    {
        $leafNames = collect(array_keys($this->scalarPaths($item)))
            ->map(fn (string $leafPath): string => $this->normalizeJsonKey((string) str($leafPath)->afterLast('.')))
            ->all();
        $hasTitle = collect($leafNames)->contains(fn (string $key): bool => in_array($key, ['title', 'name', 'summary', 'subject', 'headline', 'eventtitle', 'eventname'], true));
        $hasDate = collect($leafNames)->contains(fn (string $key): bool => str_contains($key, 'date') || str_contains($key, 'start') || $key === 'dtstart');
        $containerName = $this->normalizeJsonKey((string) str($path)->afterLast('.'));
        $containerBonus = in_array($containerName, ['events', 'event', 'items', 'results', 'data', 'docs', 'entries', 'nodes', 'records', 'occurrences'], true) ? 2 : 0;

        return ($hasTitle ? 4 : 0) + ($hasDate ? 5 : 0) + $containerBonus;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function inferJsonMapping(array $item): array
    {
        $aliases = [
            'title' => ['title', 'eventTitle', 'event_name', 'name', 'summary', 'subject', 'headline'],
            'starts_at' => ['starts_at', 'start.dateTime', 'start.date', 'startDateTime', 'startDatetime', 'startTimestamp', 'dtstart', 'start', 'StartDateTime', 'MeetingDateTime', 'startDate', 'start_date', 'eventDate', 'date', 'MeetingDate'],
            'start_time' => ['start_time', 'startTime', 'eventTime', 'time', 'MeetingTime'],
            'ends_at' => ['ends_at', 'end.dateTime', 'end.date', 'endDateTime', 'endDatetime', 'endTimestamp', 'dtend', 'end', 'EndDateTime', 'endDate', 'end_date'],
            'end_time' => ['end_time', 'endTime'],
            'location_name' => ['location_name', 'location.name', 'venue.name', 'venue.venue', 'place.name', 'location', 'venue', 'Location', 'MeetingLocation'],
            'location_address' => ['location_address', 'location.address', 'venue.address', 'place.address', 'address'],
            'description' => ['description', 'details', 'body', 'content', 'excerpt'],
            'event_url' => ['event_url', 'eventUrl', 'url', 'link', 'permalink', 'href'],
            'external_id' => ['external_id', 'externalId', 'id', 'uid', 'eventId', 'Id', 'ID', 'UID'],
            'all_day' => ['all_day', 'allDay', 'isAllDay'],
        ];

        $paths = $this->scalarPaths($item);
        $mapping = [];

        foreach ($aliases as $target => $candidates) {
            $matchedPath = $this->matchingJsonPath($paths, $candidates);

            if ($matchedPath !== null) {
                $mapping[$target] = $matchedPath;
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, scalar|null>
     */
    private function scalarPaths(array $value, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 7) {
            return [];
        }

        $paths = [];

        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($child)) {
                $paths += $this->scalarPaths($child, $path, $depth + 1);
            } elseif (is_scalar($child) || $child === null) {
                $paths[$path] = $child;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, scalar|null>  $paths
     * @param  array<int, string>  $candidates
     */
    private function matchingJsonPath(array $paths, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidateLower = mb_strtolower($candidate);

            foreach (array_keys($paths) as $path) {
                $pathLower = mb_strtolower($path);

                if (
                    $pathLower === $candidateLower
                    || (str_contains($candidateLower, '.') && str_ends_with($pathLower, '.'.$candidateLower))
                ) {
                    return $path;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $candidateNormalized = $this->normalizeJsonKey($candidate);

            foreach (array_keys($paths) as $path) {
                $leaf = (string) str($path)->afterLast('.');

                if ($this->normalizeJsonKey($leaf) === $candidateNormalized) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function normalizeJsonKey(string $key): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $key) ?? '');
    }

    /**
     * @return array{config: array<string, mixed>, confidence: float, warnings: array<int, string>}
     */
    private function draftHtml(string $sourceUrl, string $body): array
    {
        $crawler = new Crawler($body, $sourceUrl);

        if ($this->hasDatedJsonLdEvent($crawler)) {
            return [
                'config' => [
                    'profile' => 'json_ld_events',
                    'timezone' => null,
                    'max_items' => 100,
                ],
                'confidence' => 0.96,
                'warnings' => [],
            ];
        }

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

    private function hasDatedJsonLdEvent(Crawler $crawler): bool
    {
        try {
            foreach ($crawler->filter('script[type*="ld+json"]') as $node) {
                $payload = json_decode(trim((string) $node->textContent), true);

                if (is_array($payload) && $this->jsonLdContainsDatedEvent($payload)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @param  array<string|int, mixed>  $value
     */
    private function jsonLdContainsDatedEvent(array $value, int $depth = 0): bool
    {
        if ($depth > 10) {
            return false;
        }

        $type = $value['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        $isEvent = collect($types)->contains(
            fn (mixed $candidate): bool => is_string($candidate) && mb_strtolower($candidate) === 'event'
        );

        if ($isEvent && is_scalar($value['startDate'] ?? null) && trim((string) $value['startDate']) !== '') {
            return true;
        }

        foreach ($value as $child) {
            if (is_array($child) && $this->jsonLdContainsDatedEvent($child, $depth + 1)) {
                return true;
            }
        }

        return false;
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
