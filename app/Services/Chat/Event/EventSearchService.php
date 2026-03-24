<?php

namespace App\Services\Chat\Event;

use App\Models\City;
use App\Models\Event;
use App\Models\EventSourceItem;
use Illuminate\Support\Carbon;

class EventSearchService
{
    /**
     * @param  array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null  $window
     * @return array{
     *     window: array{
     *         start_at: Carbon,
     *         end_at: Carbon,
     *         label: string,
     *         is_explicit: bool,
     *         parse_confidence: float
     *     },
     *     events: array<int, array{
     *         title: string,
     *         starts_at: string,
     *         ends_at: string|null,
     *         all_day: bool,
     *         location_name: string|null,
     *         summary: string,
     *         source_url: string,
     *         source_name: string
     *     }>,
     *     total: int,
     *     has_more: bool
     * }
     */
    public function search(
        City $city,
        ?array $window,
        string $question = '',
        ?int $limit = null,
    ): array {
        $timezone = $city->timezone ?: config('app.timezone', 'UTC');
        $resolvedWindow = $window ?? $this->defaultWindow($timezone);
        $limit = max(1, min(20, $limit ?? (int) config('chat.events.max_results', 8)));
        $windowStart = $resolvedWindow['start_at']->copy()->utc();
        $windowEnd = $resolvedWindow['end_at']->copy()->utc();
        $terms = $this->keywordTerms($question);
        $meetingFocused = $this->isMeetingFocusedQuery($question);

        $events = Event::query()
            ->where('city_id', $city->id)
            ->whereNotNull('starts_at')
            ->with([
                'sourceItems' => fn ($builder) => $builder
                    ->with('eventSource')
                    ->orderByDesc('fetched_at'),
            ])
            ->orderBy('starts_at')
            ->get();

        $filtered = $events
            ->filter(function (Event $event) use ($windowStart, $windowEnd): bool {
                $eventStart = $event->starts_at?->copy()->utc();
                $eventEnd = ($event->ends_at ?? $event->starts_at)?->copy()->utc();

                if (! $eventStart || ! $eventEnd) {
                    return false;
                }

                return $eventStart->lessThanOrEqualTo($windowEnd)
                    && $eventEnd->greaterThanOrEqualTo($windowStart);
            })
            ->filter(function (Event $event) use ($terms): bool {
                return $this->matchesKeywordTerms($event, $terms);
            })
            ->values();

        if ($meetingFocused) {
            $filtered = $filtered
                ->filter(fn (Event $event): bool => $this->isRelevantMeetingEvent($event))
                ->sort(function (Event $left, Event $right): int {
                    $scoreComparison = $this->meetingRelevanceScore($right) <=> $this->meetingRelevanceScore($left);

                    if ($scoreComparison !== 0) {
                        return $scoreComparison;
                    }

                    return ($left->starts_at?->getTimestamp() ?? PHP_INT_MAX)
                        <=> ($right->starts_at?->getTimestamp() ?? PHP_INT_MAX);
                })
                ->values();
        }

        $total = $filtered->count();
        $events = $filtered->take($limit);

        return [
            'window' => $resolvedWindow,
            'events' => $events->map(fn (Event $event): array => $this->mapEvent($event, $timezone))->all(),
            'total' => $total,
            'has_more' => $total > $events->count(),
        ];
    }

    private function matchesKeywordTerms(Event $event, array $terms): bool
    {
        if ($terms === []) {
            return true;
        }

        $haystack = $this->eventContentHaystack($event);

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }
     */
    private function defaultWindow(string $timezone): array
    {
        $now = Carbon::now($timezone);

        return [
            'start_at' => $now->copy()->startOfDay(),
            'end_at' => $now->copy()->addDays(30)->endOfDay(),
            'label' => 'next 30 days',
            'is_explicit' => false,
            'parse_confidence' => 0.7,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function keywordTerms(string $question): array
    {
        $stopWords = [
            'what',
            'whats',
            'what\'s',
            'or',
            'are',
            'is',
            'going',
            'on',
            'in',
            'the',
            'this',
            'next',
            'today',
            'tomorrow',
            'tonight',
            'week',
            'weekend',
            'month',
            'events',
            'event',
            'activity',
            'activities',
            'city',
        ];

        return collect(preg_split('/\s+/', mb_strtolower($question)) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B.,!?;:\"'`()[]{}"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3)
            ->reject(fn (string $term): bool => in_array($term, $stopWords, true))
            ->flatMap(function (string $term): array {
                if (str_ends_with($term, 's') && mb_strlen($term) > 4) {
                    return [$term, mb_substr($term, 0, -1)];
                }

                return [$term];
            })
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    private function isMeetingFocusedQuery(string $question): bool
    {
        $question = mb_strtolower($question);

        foreach ([
            'city council',
            'board',
            'commission',
            'public meeting',
            'public meetings',
            'agenda',
        ] as $signal) {
            if (str_contains($question, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function isRelevantMeetingEvent(Event $event): bool
    {
        $title = mb_strtolower((string) ($event->title ?? ''));
        $content = $this->eventContentHaystack($event);

        return $this->containsMeetingSignal($title)
            || ($this->hasGovernmentMeetingSource($event) && $this->containsMeetingSignal($content));
    }

    private function meetingRelevanceScore(Event $event): int
    {
        $title = mb_strtolower((string) ($event->title ?? ''));
        $content = $this->eventContentHaystack($event);
        $score = 0;

        if ($this->containsMeetingSignal($title)) {
            $score += 10;
        }

        if ($this->containsMeetingSignal($content)) {
            $score += 3;
        }

        if ($this->hasGovernmentMeetingSource($event)) {
            $score += 4;
        }

        return $score;
    }

    private function eventContentHaystack(Event $event): string
    {
        return mb_strtolower(implode(' ', [
            (string) ($event->title ?? ''),
            (string) ($event->description ?? ''),
            (string) ($event->location_name ?? ''),
            (string) ($event->location_address ?? ''),
        ]));
    }

    private function eventSourceHaystack(Event $event): string
    {
        return mb_strtolower(implode(' ', [
            (string) ($event->event_url ?? ''),
            ...$event->sourceItems
                ->map(fn (EventSourceItem $item): string => implode(' ', [
                    (string) ($item->source_url ?? ''),
                    (string) ($item->eventSource?->name ?? ''),
                    (string) ($item->eventSource?->source_url ?? ''),
                ]))
                ->all(),
        ]));
    }

    private function hasGovernmentMeetingSource(Event $event): bool
    {
        $source = $this->eventSourceHaystack($event);

        foreach ([
            'agenda center',
            'civicengage',
            '/agenda',
            'government',
            'city council',
            'board',
            'commission',
            'committee',
            'minutes',
            'public meeting',
            'public meetings',
        ] as $signal) {
            if (str_contains($source, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function containsMeetingSignal(string $haystack): bool
    {
        foreach ([
            'city council',
            'council meeting',
            'board meeting',
            'board of',
            'commission',
            'committee',
            'public meeting',
            'public hearing',
            'hearing',
            'agenda',
            'study session',
            'special session',
            'regular session',
        ] as $signal) {
            if (str_contains($haystack, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     title: string,
     *     starts_at: string,
     *     ends_at: string|null,
     *     all_day: bool,
     *     location_name: string|null,
     *     summary: string,
     *     source_url: string,
     *     source_name: string
     * }
     */
    private function mapEvent(Event $event, string $timezone): array
    {
        [$sourceUrl, $sourceName] = $this->sourceForEvent($event);
        $summary = $this->summaryForEvent($event);

        return [
            'title' => trim((string) $event->title) ?: 'Untitled event',
            'starts_at' => (string) $event->starts_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $event->ends_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'all_day' => (bool) $event->all_day,
            'location_name' => $event->location_name ? trim((string) $event->location_name) : null,
            'summary' => $summary,
            'source_url' => $sourceUrl,
            'source_name' => $sourceName,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sourceForEvent(Event $event): array
    {
        $eventUrl = trim((string) ($event->event_url ?? ''));

        if ($eventUrl !== '') {
            return [$eventUrl, 'Event details'];
        }

        $items = $event->sourceItems
            ->sortByDesc(fn (EventSourceItem $item): int => (int) $item->fetched_at?->getTimestamp())
            ->values();

        foreach ($items as $item) {
            $itemUrl = trim((string) ($item->source_url ?? ''));

            if ($itemUrl !== '') {
                return [
                    $itemUrl,
                    trim((string) ($item->eventSource?->name ?? 'Event source')) ?: 'Event source',
                ];
            }
        }

        foreach ($items as $item) {
            $sourceHome = trim((string) ($item->eventSource?->source_url ?? ''));

            if ($sourceHome !== '') {
                return [
                    $sourceHome,
                    trim((string) ($item->eventSource?->name ?? 'Event source')) ?: 'Event source',
                ];
            }
        }

        return ['', 'Event source'];
    }

    private function summaryForEvent(Event $event): string
    {
        $summary = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($event->description ?? ''))) ?? '');

        if ($summary === '') {
            return trim((string) $event->title) ?: 'Event';
        }

        return mb_substr($summary, 0, 240);
    }
}
