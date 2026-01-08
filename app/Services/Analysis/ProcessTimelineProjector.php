<?php

namespace App\Services\Analysis;

use App\Models\Article;
use App\Models\ProcessTimelineItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessTimelineProjector
{
    public function projectForArticle(Article $article, ?array $payload = null): void
    {
        $article->loadMissing(['analysis', 'city']);

        $timeline = $this->extractTimeline($article, $payload);

        DB::transaction(function () use ($article, $timeline) {
            ProcessTimelineItem::query()
                ->where('article_id', $article->id)
                ->where('source', 'analysis_llm')
                ->delete();

            if ($timeline['items'] === []) {
                return;
            }

            $rows = $this->mapTimelineItems($article, $timeline);

            if ($rows === []) {
                return;
            }

            ProcessTimelineItem::query()->insert($rows);
        });
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, current_key: string|null}
     */
    private function extractTimeline(Article $article, ?array $payload): array
    {
        if (is_array($payload) && array_key_exists('process_timeline', $payload)) {
            return $this->normalizeTimeline($payload['process_timeline'] ?? null);
        }

        $analysis = $article->analysis;

        if (! $analysis) {
            return ['items' => [], 'current_key' => null];
        }

        $finalScores = $this->normalizePayload($analysis->final_scores ?? null);
        $timeline = $this->normalizeTimeline($finalScores['process_timeline'] ?? null);

        if ($timeline['items'] !== []) {
            return $timeline;
        }

        $llmScores = $this->normalizePayload($analysis->llm_scores ?? null);

        return $this->normalizeTimeline($llmScores['process_timeline'] ?? null);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, current_key: string|null}
     */
    private function normalizeTimeline(mixed $timeline): array
    {
        if (! is_array($timeline)) {
            return ['items' => [], 'current_key' => null];
        }

        $items = $timeline['items'] ?? [];

        return [
            'items' => is_array($items) ? $items : [],
            'current_key' => $this->stringValue($timeline['current_key'] ?? null),
        ];
    }

    /**
     * @param  array{items: array<int, array<string, mixed>>, current_key: string|null}  $timeline
     * @return array<int, array<string, mixed>>
     */
    private function mapTimelineItems(Article $article, array $timeline): array
    {
        $timezone = $article->city?->timezone ?: config('app.timezone');
        $normalized = $this->normalizeItems($timeline['items'], $timeline['current_key'], $timezone);

        if ($normalized === []) {
            return [];
        }

        $ordered = $this->orderItems($normalized);
        $now = now();
        $rows = [];
        $position = 1;

        foreach ($ordered as $item) {
            $rows[] = [
                'article_id' => $article->id,
                'city_id' => $article->city_id,
                'key' => $item['key'],
                'label' => $item['label'],
                'status' => $item['status'],
                'date' => $item['date'],
                'has_time' => $item['has_time'],
                'badge_text' => $item['badge_text'],
                'note' => $item['note'],
                'evidence_json' => $item['evidence'] !== [] ? $this->encodePayload($item['evidence']) : null,
                'source' => 'analysis_llm',
                'source_payload' => $this->encodePayload($item['source_payload']),
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     status: string,
     *     date: \Illuminate\Support\Carbon|null,
     *     has_time: bool,
     *     badge_text: string|null,
     *     note: string|null,
     *     evidence: array<int, array{quote: string, start?: int, end?: int}>,
     *     source_payload: array<string, mixed>,
     *     order_index: int
     * }>
     */
    private function normalizeItems(array $items, ?string $currentKey, string $timezone): array
    {
        $normalized = [];
        $seen = [];
        $nowLocal = now($timezone);
        $currentKey = $currentKey ? Str::lower($currentKey) : null;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $this->stringValue($item['key'] ?? null);
            $label = $this->stringValue($item['label'] ?? null);

            if (! $key || ! $label) {
                continue;
            }

            $dedupeKey = Str::lower($key);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;

            $hasTime = false;
            $date = $this->parseDate($this->stringValue($item['date'] ?? null), $timezone, $hasTime);
            $endsAt = $this->parseDate($this->stringValue($item['ends_at'] ?? null), $timezone);
            $statusAndBadge = $this->normalizeStatusAndBadge(
                $key,
                $date,
                $endsAt,
                $this->stringValue($item['status'] ?? null),
                $timezone,
                $nowLocal
            );
            $status = $statusAndBadge['status'];
            $badgeText = $statusAndBadge['badge_text'];

            if ($currentKey !== null && $currentKey === $dedupeKey && $status !== 'completed' && $date === null) {
                $status = 'current';
            }
            $note = $this->stringValue($item['note'] ?? null);
            $evidence = $this->normalizeEvidence($item['evidence'] ?? []);
            $orderIndex = is_numeric($item['position'] ?? null) ? (int) $item['position'] : (int) $index;

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'date' => $date,
                'has_time' => $hasTime,
                'badge_text' => $badgeText,
                'note' => $note,
                'evidence' => $evidence,
                'source_payload' => $item,
                'order_index' => $orderIndex,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function orderItems(array $items): array
    {
        usort($items, fn (array $left, array $right): int => $left['order_index'] <=> $right['order_index']);

        if ($this->hasCleanSequence($items)) {
            return $items;
        }

        usort($items, fn (array $left, array $right): int => $this->compareItems($left, $right));

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function hasCleanSequence(array $items): bool
    {
        if (count($items) < 2) {
            return true;
        }

        $lastWeight = null;

        foreach ($items as $item) {
            $weight = $this->statusWeight($item['status'] ?? 'unknown');

            if ($lastWeight !== null && $weight < $lastWeight) {
                return false;
            }

            $lastWeight = $weight;
        }

        return true;
    }

    private function compareItems(array $left, array $right): int
    {
        $leftWeight = $this->statusWeight($left['status'] ?? 'unknown');
        $rightWeight = $this->statusWeight($right['status'] ?? 'unknown');

        if ($leftWeight !== $rightWeight) {
            return $leftWeight <=> $rightWeight;
        }

        $leftDate = $left['date'] ?? null;
        $rightDate = $right['date'] ?? null;

        if (! $leftDate && ! $rightDate) {
            return $left['order_index'] <=> $right['order_index'];
        }

        if (! $leftDate) {
            return 1;
        }

        if (! $rightDate) {
            return -1;
        }

        $result = $leftDate <=> $rightDate;

        return $result !== 0 ? $result : ($left['order_index'] <=> $right['order_index']);
    }

    private function statusWeight(string $status): int
    {
        return match ($status) {
            'completed' => 1,
            'current' => 2,
            'upcoming' => 3,
            default => 4,
        };
    }

    private function normalizeStatus(mixed $status): string
    {
        if (! is_string($status)) {
            return 'unknown';
        }

        $status = Str::lower(trim($status));

        if (! in_array($status, ['completed', 'current', 'upcoming', 'unknown'], true)) {
            return 'unknown';
        }

        return $status;
    }

    /**
     * @return array{status: string, badge_text: string|null}
     */
    private function normalizeStatusAndBadge(
        string $key,
        ?Carbon $startsAt,
        ?Carbon $endsAt,
        ?string $llmStatus,
        string $timezone,
        Carbon $nowLocal
    ): array {
        $status = $this->normalizeStatus($llmStatus);

        if ($startsAt) {
            $startsLocal = $startsAt->clone()->timezone($timezone);

            if ($startsLocal->lt($nowLocal->copy()->subDay())) {
                $status = 'completed';
            } elseif ($startsLocal->gt($nowLocal->copy()->addDay())) {
                $status = 'upcoming';
            } else {
                $status = 'current';
            }
        }

        $badgeText = $this->shouldShowOpenNow($key, $startsAt, $endsAt, $nowLocal, $timezone)
            ? 'OPEN NOW'
            : null;

        return [
            'status' => $status,
            'badge_text' => $badgeText,
        ];
    }

    private function shouldShowOpenNow(
        string $key,
        ?Carbon $startsAt,
        ?Carbon $endsAt,
        Carbon $nowLocal,
        string $timezone
    ): bool {
        $allowedKeys = [
            'public_comment_period',
            'comment_period',
            'application_period',
        ];

        if (! in_array(Str::lower($key), $allowedKeys, true)) {
            return false;
        }

        if (! $startsAt || ! $endsAt) {
            return false;
        }

        $startsLocal = $startsAt->clone()->timezone($timezone);
        $endsLocal = $endsAt->clone()->timezone($timezone);

        if ($endsLocal->lt($startsLocal)) {
            return false;
        }

        return $nowLocal->between($startsLocal, $endsLocal, true);
    }

    private function parseDate(?string $date, string $timezone, ?bool &$hasTime = null): ?Carbon
    {
        if (! $date) {
            $hasTime = false;

            return null;
        }

        $hasTime = $this->dateHasTime($date);

        try {
            $local = Carbon::parse($date, $timezone);

            if (! $hasTime) {
                $local = $local->setTime(12, 0);
            }

            return $local->clone()->utc();
        } catch (\Throwable) {
            $hasTime = false;

            return null;
        }
    }

    private function dateHasTime(string $date): bool
    {
        if (preg_match('/\d{1,2}:\d{2}/', $date) === 1) {
            return true;
        }

        return Str::contains($date, 'T');
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{quote: string, start?: int, end?: int}>
     */
    private function normalizeEvidence(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quote = $this->stringValue($item['quote'] ?? null);

            if ($quote === null) {
                continue;
            }

            $entry = ['quote' => $quote];

            $start = $this->numberValue($item['start'] ?? null);
            if ($start !== null) {
                $entry['start'] = $start;
            }

            $end = $this->numberValue($item['end'] ?? null);
            if ($end !== null) {
                $entry['end'] = $end;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function numberValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }
}
