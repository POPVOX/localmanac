<?php

namespace App\Services\Articles;

use Illuminate\Support\Str;

class MeetingSummaryFallback
{
    /**
     * @return array{whats_happening: string|null, why_it_matters: string|null}
     */
    public function narrative(
        ?string $title,
        ?string $cleanedText,
        ?string $whatsHappening = null,
        ?string $whyItMatters = null,
    ): array {
        $title = $this->stringValue($title);
        $cleanedText = $this->stringValue($cleanedText);

        if ($cleanedText === null || ! $this->isMeetingLike($title, $cleanedText)) {
            return [
                'whats_happening' => $this->stringValue($whatsHappening),
                'why_it_matters' => $this->stringValue($whyItMatters),
            ];
        }

        if ($this->shouldReplaceWhatsHappening($whatsHappening)) {
            $whatsHappening = $this->deriveWhatsHappening($title, $cleanedText) ?? $this->stringValue($whatsHappening);
        }

        if ($this->shouldReplaceWhyItMatters($whyItMatters)) {
            $whyItMatters = $this->deriveWhyItMatters($cleanedText);
        }

        return [
            'whats_happening' => $this->stringValue($whatsHappening),
            'why_it_matters' => $this->stringValue($whyItMatters),
        ];
    }

    private function isMeetingLike(?string $title, string $cleanedText): bool
    {
        $haystack = mb_strtolower(trim(($title ?? '').' '.$cleanedText));

        foreach ([
            'meeting',
            'recap',
            'notes',
            'minutes',
            'agenda',
            'advisory board',
            'city council',
            'commission',
            'public hearing',
        ] as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function shouldReplaceWhatsHappening(?string $whatsHappening): bool
    {
        $whatsHappening = $this->stringValue($whatsHappening);

        if ($whatsHappening === null) {
            return true;
        }

        foreach ([
            'various items were discussed',
            'important local issues affecting',
            'community issues and opportunities',
            'focusing on important local issues',
        ] as $phrase) {
            if (str_contains(mb_strtolower($whatsHappening), $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function shouldReplaceWhyItMatters(?string $whyItMatters): bool
    {
        $whyItMatters = $this->stringValue($whyItMatters);

        if ($whyItMatters === null) {
            return true;
        }

        foreach ([
            'stay informed',
            'community decisions',
            'local governance',
            'community engagement',
            'directly impact local initiatives',
        ] as $phrase) {
            if (str_contains(mb_strtolower($whyItMatters), $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function deriveWhatsHappening(?string $title, string $cleanedText): ?string
    {
        $segments = $this->segments($cleanedText, $title);

        if ($segments === []) {
            return null;
        }

        $bulletSummary = $this->bulletSummary($title, $segments);

        if ($bulletSummary !== null) {
            return $bulletSummary;
        }

        $selected = $this->selectSummarySegments($segments);

        if ($selected === []) {
            return null;
        }

        return $this->joinSentences($selected, 320);
    }

    private function deriveWhyItMatters(string $cleanedText): ?string
    {
        $lower = mb_strtolower($cleanedText);
        $channels = [];

        if (str_contains($lower, 'survey') && (str_contains($lower, 'public input') || str_contains($lower, 'submit their thoughts') || str_contains($lower, 'available online'))) {
            $channels[] = 'the public survey mentioned in the meeting notes';
        }

        if (str_contains($lower, 'interactive map')) {
            $channels[] = 'the interactive map for community feedback';
        }

        if (str_contains($lower, 'public hearing')) {
            $channels[] = 'the upcoming public hearing';
        }

        if (str_contains($lower, 'next meeting')) {
            $channels[] = 'the next meeting';
        }

        $channels = array_values(array_unique($channels));

        if ($channels === []) {
            return null;
        }

        return 'Residents can still weigh in through '.$this->joinList(array_slice($channels, 0, 3)).'.';
    }

    /**
     * @return array<int, string>
     */
    private function segments(string $cleanedText, ?string $title): array
    {
        $blocks = preg_split('/\R{2,}/u', $cleanedText) ?: [];
        $segments = [];

        foreach ($blocks as $block) {
            $segment = $this->normalizeSegment($block);

            if ($segment === null || $this->isDuplicateTitle($segment, $title) || $this->isMetadataSegment($segment)) {
                continue;
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    private function bulletSummary(?string $title, array $segments): ?string
    {
        $items = [];

        foreach ($segments as $segment) {
            if (! $this->isDecisionLikeSegment($segment)) {
                continue;
            }

            $cleaned = $this->cleanDecisionSegment($segment);

            if ($cleaned !== null) {
                $items[] = $cleaned;
            }
        }

        $items = array_values(array_unique($items));

        if (count($items) < 2) {
            return null;
        }

        $subject = 'The meeting';

        if ($title !== null && str_contains(mb_strtolower($title), 'city council')) {
            $subject = trim($title);
        } elseif ($title !== null) {
            $subject = trim($title);
        }

        $summary = $subject.' covered '.$this->joinList(array_slice($items, 0, 3)).'.';

        return $this->trimToLength($summary, 320);
    }

    /**
     * @return array<int, string>
     */
    private function selectSummarySegments(array $segments): array
    {
        $scored = [];

        foreach ($segments as $index => $segment) {
            $score = $this->segmentScore($segment);

            if ($score < 3) {
                continue;
            }

            $scored[] = [
                'index' => $index,
                'score' => $score,
                'segment' => $this->summarySentence($segment),
            ];
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, static function (array $left, array $right): int {
            $scoreComparison = $right['score'] <=> $left['score'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $left['index'] <=> $right['index'];
        });

        $selected = array_slice($scored, 0, 3);

        usort($selected, static fn (array $left, array $right): int => $left['index'] <=> $right['index']);

        return array_values(array_filter(array_map(
            static fn (array $item): ?string => $item['segment'],
            $selected
        )));
    }

    private function segmentScore(string $segment): int
    {
        $lower = mb_strtolower($segment);
        $score = 0;

        foreach ([
            'approved' => 4,
            'motion' => 4,
            'public comment' => 4,
            'public hearing' => 4,
            'survey' => 4,
            'master plan' => 4,
            'economic mobility' => 4,
            'zoning' => 3,
            'presented' => 3,
            'discussion' => 3,
            'discussed' => 3,
            'meeting' => 2,
            'board' => 2,
            'council' => 2,
            'commission' => 2,
            'next meeting' => 2,
            'deadline' => 2,
            'interactive map' => 2,
        ] as $keyword => $weight) {
            if (str_contains($lower, $keyword)) {
                $score += $weight;
            }
        }

        if (preg_match('/\b\d{1,2}:\d{2}\b|\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/i', $segment) === 1) {
            $score++;
        }

        if (preg_match('/\b[A-Z][a-z]+ [A-Z][a-z]+\b/', $segment) === 1) {
            $score++;
        }

        if (preg_match('/^those in attendance\b/i', $segment) === 1) {
            $score -= 5;
        }

        return $score;
    }

    private function isDecisionLikeSegment(string $segment): bool
    {
        $trimmed = trim($segment);

        return preg_match('/^\s*(?:[-*•]|\d+\.)\s+/u', $trimmed) === 1
            || str_contains($trimmed, 'Approved ')
            || str_contains($trimmed, 'Motion to ')
            || str_contains($trimmed, 'Failed ');
    }

    private function cleanDecisionSegment(string $segment): ?string
    {
        $segment = preg_replace('/^\s*(?:[-*•]|\d+\.)\s+/u', '', trim($segment)) ?? trim($segment);
        $segment = preg_replace('/\s+[–-]\s+Approved\s+\d+\/\d+.*$/u', '', $segment) ?? $segment;
        $segment = preg_replace('/\s+[–-]\s+Failed.*$/u', '', $segment) ?? $segment;
        $segment = preg_replace('/\s+\(No[^)]*\)$/u', '', $segment) ?? $segment;
        $segment = trim($segment, " \t\n\r\0\x0B.:;");

        if ($segment === '' || mb_strlen($segment) < 12) {
            return null;
        }

        return $segment;
    }

    private function summarySentence(string $segment): ?string
    {
        $sentence = Str::of($segment)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
        $original = $sentence;

        if ($sentence === '') {
            return null;
        }

        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $sentence, $matches) === 1) {
            $sentence = $matches[1];
        }

        if (str_contains($original, '! ') && str_ends_with($sentence, '!')) {
            $sentence = $original;
        }

        return $this->trimToLength($sentence, 180);
    }

    private function joinSentences(array $sentences, int $maxChars): ?string
    {
        $summary = '';

        foreach ($sentences as $sentence) {
            $candidate = $summary === '' ? $sentence : $summary.' '.$sentence;

            if (mb_strlen($candidate) > $maxChars) {
                break;
            }

            $summary = $candidate;
        }

        return $summary === '' ? null : $summary;
    }

    private function joinList(array $items): string
    {
        $items = array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringValue(is_string($item) ? $item : null),
            $items
        )));

        if ($items === []) {
            return '';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        if (count($items) === 2) {
            return $items[0].' and '.$items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items).', and '.$last;
    }

    private function normalizeSegment(string $segment): ?string
    {
        $segment = trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment);

        if ($segment === '' || mb_strlen($segment) < 20) {
            return null;
        }

        return $segment;
    }

    private function isDuplicateTitle(string $segment, ?string $title): bool
    {
        $title = $this->stringValue($title);

        if ($title === null) {
            return false;
        }

        $normalizedSegment = mb_strtolower(trim($segment, ' .'));
        $normalizedTitle = mb_strtolower(trim($title, ' .'));

        return $normalizedSegment === $normalizedTitle
            || str_starts_with($normalizedSegment, $normalizedTitle)
            || str_starts_with($normalizedTitle, $normalizedSegment);
    }

    private function isMetadataSegment(string $segment): bool
    {
        foreach ([
            '/^documenter name:/i',
            '/^agency:/i',
            '/^date:/i',
            '/meeting summary notes/i',
            '/^see more about this meeting/i',
            '/^follow-?up questions$/i',
            '/^if you believe anything in these notes is inaccurate/i',
            '/^those in attendance\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    private function trimToLength(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return trim($text);
        }

        $slice = mb_substr($text, 0, $maxChars);
        $trimmed = preg_replace('/\s+\S*$/u', '', $slice) ?? $slice;

        return trim($trimmed);
    }

    private function stringValue(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
