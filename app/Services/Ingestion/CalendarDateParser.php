<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Carbon;
use Throwable;

class CalendarDateParser
{
    /**
     * @return array{starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}|null
     */
    public function parse(?string $dateText, ?string $timeText, string $timezone): ?array
    {
        $dateText = $this->normalizeWhitespace($dateText ?? '');
        $timeText = $this->normalizeWhitespace($timeText ?? '');

        if ($dateText === '' && $timeText === '') {
            return null;
        }

        if ($dateText === '' && $timeText !== '') {
            return $this->parseIso($timeText, $timezone);
        }

        $allDay = $this->containsAllDay($dateText) || $this->containsAllDay($timeText);

        if ($timeText === '') {
            return $this->parseDateOnly($dateText, $timezone);
        }

        $date = $this->parseDateValue($dateText, $timezone);

        if (! $date) {
            return null;
        }

        if ($allDay) {
            return [
                'starts_at' => $date->copy()->startOfDay(),
                'ends_at' => null,
                'all_day' => true,
            ];
        }

        $range = $this->parseTimeRange($timeText, $date, $timezone);

        if ($range) {
            return [
                'starts_at' => $range['start'],
                'ends_at' => $range['end'],
                'all_day' => false,
            ];
        }

        $combined = $this->parseDateValue("{$dateText} {$timeText}", $timezone);

        if ($combined) {
            return [
                'starts_at' => $combined,
                'ends_at' => null,
                'all_day' => false,
            ];
        }

        return null;
    }

    /**
     * @return array{starts_at: ?Carbon, ends_at: ?Carbon, all_day: bool}|null
     */
    public function parseIso(?string $value, string $timezone): ?array
    {
        $value = $this->normalizeWhitespace($value ?? '');

        if ($value === '') {
            return null;
        }

        $parsed = $this->parseDateValue($value, $timezone);

        if (! $parsed) {
            return null;
        }

        $allDay = ! $this->containsTime($value);

        return [
            'starts_at' => $allDay ? $parsed->copy()->startOfDay() : $parsed,
            'ends_at' => null,
            'all_day' => $allDay,
        ];
    }

    private function parseDateOnly(string $dateText, string $timezone): ?array
    {
        $split = $this->splitDateTime($dateText);

        if ($split) {
            return $this->parse($split['date'], $split['time'], $timezone);
        }

        $parsed = $this->parseDateValue($dateText, $timezone);

        if (! $parsed) {
            return null;
        }

        if ($this->containsTime($dateText)) {
            return [
                'starts_at' => $parsed,
                'ends_at' => null,
                'all_day' => false,
            ];
        }

        return [
            'starts_at' => $parsed->copy()->startOfDay(),
            'ends_at' => null,
            'all_day' => true,
        ];
    }

    private function parseDateValue(string $value, string $timezone): ?Carbon
    {
        try {
            return Carbon::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{start: Carbon, end: ?Carbon}|null
     */
    private function parseTimeRange(string $value, Carbon $date, string $timezone): ?array
    {
        $value = $this->normalizeWhitespace($value);

        if ($value === '') {
            return null;
        }

        $parts = preg_split('/\s*(?:-|to|–|—)\s*/i', $value) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part) => $part !== ''));

        if ($parts === []) {
            return null;
        }

        $meridiem = $this->extractMeridiem(end($parts) ?: '');
        $times = [];

        foreach ($parts as $part) {
            $token = $this->normalizeTimeToken($part, $meridiem);
            $parsed = $this->parseTimeValue($token, $date, $timezone);

            if ($parsed) {
                $times[] = $parsed;
            }
        }

        if ($times === []) {
            return null;
        }

        return [
            'start' => $times[0],
            'end' => $times[1] ?? null,
        ];
    }

    private function parseTimeValue(string $value, Carbon $date, string $timezone): ?Carbon
    {
        $value = $this->normalizeTimeToken($value, null);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($date->format('Y-m-d').' '.$value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function extractMeridiem(string $value): ?string
    {
        if (preg_match('/\b(am|pm)\b/i', $value, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function normalizeTimeToken(string $value, ?string $fallbackMeridiem): string
    {
        $value = strtolower($value);
        $value = str_replace(['a.m.', 'p.m.'], ['am', 'pm'], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/(\d)(am|pm)$/i', '$1 $2', $value) ?? $value;

        if ($fallbackMeridiem && ! preg_match('/\b(am|pm)\b/i', $value)) {
            $value = "{$value} ".strtolower($fallbackMeridiem);
        }

        return trim($value);
    }

    private function containsAllDay(string $value): bool
    {
        return preg_match('/all\s*day/i', $value) === 1;
    }

    private function containsTime(string $value): bool
    {
        return preg_match('/\d{1,2}:\d{2}/', $value) === 1
            || preg_match('/\b(am|pm)\b/i', $value) === 1;
    }

    /**
     * @return array{date: string, time: string}|null
     */
    private function splitDateTime(string $value): ?array
    {
        $value = $this->normalizeWhitespace($value);

        if ($value === '' || ! $this->containsTime($value)) {
            return null;
        }

        $lastComma = strrpos($value, ',');

        if ($lastComma === false) {
            return null;
        }

        $datePart = trim(substr($value, 0, $lastComma));
        $timePart = trim(substr($value, $lastComma + 1));

        if ($datePart === '' || $timePart === '') {
            return null;
        }

        return [
            'date' => $datePart,
            'time' => $timePart,
        ];
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
