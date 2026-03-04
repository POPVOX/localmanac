<?php

namespace App\Services\Chat\Event;

class EventIntentDetector
{
    /**
     * @var array<int, string>
     */
    private const EVENT_KEYWORDS = [
        'event',
        'events',
        'meeting',
        'meetings',
        'agenda',
        'agendas',
        'hearing',
        'hearings',
        'city council',
        'board meeting',
        'commission meeting',
        'public meeting',
        'public meetings',
        'concert',
        'concerts',
        'festival',
        'festivals',
        'show',
        'shows',
        'performance',
        'performances',
        'things to do',
        "what's going on",
        'whats going on',
        'what is going on',
        'happening',
        'activities',
        'calendar',
        'weekend plans',
        'live music',
    ];

    /**
     * @var array<int, string>
     */
    private const TEMPORAL_KEYWORDS = [
        'today',
        'tomorrow',
        'tonight',
        'this weekend',
        'next weekend',
        'this week',
        'next week',
        'this month',
        'next month',
        'weekend',
    ];

    public function isEventIntent(string $question): bool
    {
        $normalized = $this->normalize($question);

        if ($normalized === '') {
            return false;
        }

        if ($this->containsAny($normalized, self::EVENT_KEYWORDS)) {
            return true;
        }

        $hasTemporalSignal = $this->containsAny($normalized, self::TEMPORAL_KEYWORDS)
            || $this->containsExplicitDate($normalized);

        if (! $hasTemporalSignal) {
            return false;
        }

        if ($this->isShortTemporalQuery($normalized)) {
            return true;
        }

        return $this->containsActivityPrompt($normalized);
    }

    private function containsExplicitDate(string $question): bool
    {
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $question) === 1) {
            return true;
        }

        if (preg_match('/\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/', $question) === 1) {
            return true;
        }

        return preg_match(
            '/\b(?:jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:,\s*\d{4})?\b/i',
            $question
        ) === 1;
    }

    private function isShortTemporalQuery(string $question): bool
    {
        $wordCount = count(array_filter(preg_split('/\s+/', $question) ?: []));

        return $wordCount <= 4;
    }

    private function containsActivityPrompt(string $question): bool
    {
        foreach ([
            'what should i do',
            'what can i do',
            'what is there to do',
            'what\'s happening',
            'what is happening',
            'going on',
            'happening',
        ] as $needle) {
            if (str_contains($question, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }
}
