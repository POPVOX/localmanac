<?php

namespace App\Services\Chat\Event;

use Illuminate\Support\Carbon;
use Throwable;

class EventWindowResolver
{
    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null
     */
    public function resolve(string $question, string $timezone): ?array
    {
        $normalized = $this->normalize($question);

        if ($normalized === '') {
            return null;
        }

        $now = Carbon::now($timezone);

        if (str_contains($normalized, 'today')) {
            return $this->window(
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                'today',
                false,
                1.0,
            );
        }

        if (str_contains($normalized, 'tomorrow')) {
            $tomorrow = $now->copy()->addDay();

            return $this->window(
                $tomorrow->copy()->startOfDay(),
                $tomorrow->copy()->endOfDay(),
                'tomorrow',
                false,
                1.0,
            );
        }

        if (str_contains($normalized, 'tonight')) {
            return $this->window(
                $now->copy(),
                $now->copy()->endOfDay(),
                'tonight',
                false,
                0.95,
            );
        }

        if (str_contains($normalized, 'this weekend')) {
            [$start, $end] = $this->weekendWindow($now);

            return $this->window($start, $end, 'this weekend', false, 1.0);
        }

        if (str_contains($normalized, 'next weekend')) {
            [$start, $end] = $this->weekendWindow($now->copy()->addWeek());

            return $this->window($start, $end, 'next weekend', false, 1.0);
        }

        if (str_contains($normalized, 'this week')) {
            return $this->window(
                $now->copy()->startOfWeek(Carbon::MONDAY),
                $now->copy()->endOfWeek(Carbon::SUNDAY),
                'this week',
                false,
                0.95,
            );
        }

        if (str_contains($normalized, 'next week')) {
            $nextWeek = $now->copy()->addWeek();

            return $this->window(
                $nextWeek->copy()->startOfWeek(Carbon::MONDAY),
                $nextWeek->copy()->endOfWeek(Carbon::SUNDAY),
                'next week',
                false,
                0.95,
            );
        }

        if (str_contains($normalized, 'this month')) {
            return $this->window(
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
                'this month',
                false,
                0.95,
            );
        }

        if (str_contains($normalized, 'next month')) {
            $nextMonth = $now->copy()->addMonthNoOverflow();

            return $this->window(
                $nextMonth->copy()->startOfMonth(),
                $nextMonth->copy()->endOfMonth(),
                'next month',
                false,
                0.95,
            );
        }

        $relativeDuration = $this->extractRelativeDuration($normalized, $now);

        if ($relativeDuration !== null) {
            return $relativeDuration;
        }

        $range = $this->extractRange($question, $timezone);

        if ($range !== null) {
            return $range;
        }

        $singleDate = $this->extractSingleDate($question, $timezone);

        if ($singleDate !== null) {
            return $this->window(
                $singleDate->copy()->startOfDay(),
                $singleDate->copy()->endOfDay(),
                $singleDate->copy()->format('F j, Y'),
                true,
                0.9,
            );
        }

        return null;
    }

    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null
     */
    private function extractRelativeDuration(string $question, Carbon $now): ?array
    {
        if (preg_match('/\bnext\s+(\d{1,2})\s+days?\b/u', $question, $matches) !== 1) {
            return null;
        }

        $days = max(1, (int) ($matches[1] ?? 0));
        $start = $now->copy()->startOfDay();
        $end = $start->copy()->addDays($days - 1)->endOfDay();

        return $this->window(
            $start,
            $end,
            "next {$days} days",
            true,
            1.0,
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function weekendWindow(Carbon $reference): array
    {
        $friday = $reference->copy()
            ->startOfWeek(Carbon::MONDAY)
            ->addDays(4)
            ->startOfDay();

        $sunday = $friday->copy()->addDays(2)->endOfDay();

        return [$friday, $sunday];
    }

    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null
     */
    private function extractRange(string $question, string $timezone): ?array
    {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\s*(?:to|-|through)\s*(\d{4}-\d{2}-\d{2})\b/i', $question, $matches) === 1) {
            $start = $this->parseDateToken($matches[1], $timezone);
            $end = $this->parseDateToken($matches[2], $timezone);

            if ($start && $end) {
                return $this->sortedRangeWindow($start, $end, true, 0.95);
            }
        }

        if (preg_match('/\bfrom\s+(.+?)\s+(?:to|through|-)\s+(.+?)(?:\?|$)/i', $question, $matches) === 1) {
            $start = $this->parseDateToken($matches[1], $timezone);
            $end = $this->parseDateToken($matches[2], $timezone);

            if ($start && $end) {
                return $this->sortedRangeWindow($start, $end, true, 0.9);
            }
        }

        if (preg_match('/\bbetween\s+(.+?)\s+and\s+(.+?)(?:\?|$)/i', $question, $matches) === 1) {
            $start = $this->parseDateToken($matches[1], $timezone);
            $end = $this->parseDateToken($matches[2], $timezone);

            if ($start && $end) {
                return $this->sortedRangeWindow($start, $end, true, 0.9);
            }
        }

        return null;
    }

    private function extractSingleDate(string $question, string $timezone): ?Carbon
    {
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $question, $matches) === 1) {
            return $this->parseDateToken($matches[0], $timezone);
        }

        if (preg_match('/\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/', $question, $matches) === 1) {
            return $this->parseDateToken($matches[0], $timezone);
        }

        if (preg_match(
            '/\b(?:jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:,\s*\d{4})?\b/i',
            $question,
            $matches
        ) === 1) {
            return $this->parseDateToken($matches[0], $timezone);
        }

        return null;
    }

    private function parseDateToken(string $token, string $timezone): ?Carbon
    {
        $token = trim($token);
        $now = Carbon::now($timezone);

        if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $token, $matches) === 1) {
            $month = (int) $matches[1];
            $day = (int) $matches[2];
            $year = isset($matches[3]) ? (int) $matches[3] : (int) $now->year;

            if ($year < 100) {
                $year += 2000;
            }

            try {
                return Carbon::create($year, $month, $day, 0, 0, 0, $timezone);
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $token, $matches) === 1) {
            try {
                return Carbon::create(
                    (int) $matches[1],
                    (int) $matches[2],
                    (int) $matches[3],
                    0,
                    0,
                    0,
                    $timezone
                );
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match(
            '/\b(?:jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:,\s*\d{4})?\b/i',
            $token
        ) !== 1) {
            return null;
        }

        $hasYear = preg_match('/\b\d{4}\b/', $token) === 1;
        $normalized = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $token) ?? $token;

        if (! $hasYear) {
            $normalized .= ', '.$now->year;
        }

        try {
            return Carbon::parse($normalized, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
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
    private function sortedRangeWindow(Carbon $start, Carbon $end, bool $explicit, float $confidence): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return $this->window(
            $start,
            $end,
            $start->format('F j, Y').' to '.$end->format('F j, Y'),
            $explicit,
            $confidence,
        );
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
    private function window(
        Carbon $start,
        Carbon $end,
        string $label,
        bool $explicit,
        float $confidence,
    ): array {
        return [
            'start_at' => $start,
            'end_at' => $end,
            'label' => $label,
            'is_explicit' => $explicit,
            'parse_confidence' => $confidence,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }
}
