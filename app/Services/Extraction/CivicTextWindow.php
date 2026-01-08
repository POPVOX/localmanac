<?php

namespace App\Services\Extraction;

final class CivicTextWindow
{
    private const MAX_CHARS = 3000;

    private const OPENING_CHARS = 700;

    private const SEGMENT_TARGET_CHARS = 550;

    private const SEGMENT_MAX_CHARS = 800;

    private const MIN_SCORE = 1.0;

    private const SEPARATOR = "\n\n---\n\n";

    private const CIVIC_PHRASES = [
        'city council',
        'county',
        'council',
        'commission',
        'board',
        'department',
        'agency',
        'ordinance',
        'resolution',
        'budget',
        'zoning',
        'permit',
        'variance',
        'planning',
        'public hearing',
        'agenda',
        'meeting',
        'minutes',
        'notice',
        'public notice',
        'election',
        'ballot',
        'tax',
        'levy',
        'bond',
        'rfp',
        'rfq',
        'bid',
    ];

    private const PROCESS_PHRASES = [
        'comment period',
        'submit comments',
        'public comment',
        'register to speak',
        'call for',
        'will vote',
        'to vote',
        'scheduled',
        'hearing',
        'adopt',
        'approve',
        'consider',
        'application',
        'deadline',
    ];

    public static function build(string $cleanedText): string
    {
        $text = trim($cleanedText);

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        $opening = rtrim(mb_substr($text, 0, self::OPENING_CHARS));

        if ($opening === '') {
            return rtrim(mb_substr($text, 0, self::MAX_CHARS));
        }

        $segments = self::segments($text);
        $scoredSegments = [];

        foreach ($segments as $segment) {
            if ($segment['offset'] < self::OPENING_CHARS) {
                continue;
            }

            $score = self::segmentScore($segment['text']);

            if ($score < self::MIN_SCORE) {
                continue;
            }

            $scoredSegments[] = [
                'offset' => $segment['offset'],
                'text' => $segment['text'],
                'score' => $score,
            ];
        }

        if ($scoredSegments === []) {
            return $opening;
        }

        usort($scoredSegments, static function (array $left, array $right): int {
            $scoreComparison = $right['score'] <=> $left['score'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $left['offset'] <=> $right['offset'];
        });

        $selected = self::selectSegments($scoredSegments, mb_strlen($opening));

        if ($selected === []) {
            return $opening;
        }

        usort($selected, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        $chunks = array_merge([$opening], array_column($selected, 'text'));

        return rtrim(implode(self::SEPARATOR, $chunks));
    }

    /**
     * @return array<int, array{offset: int, text: string}>
     */
    private static function segments(string $text): array
    {
        $parts = preg_split('/\R{2,}/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);

        if ($parts === false) {
            return self::sentenceSegments($text);
        }

        $segments = [];

        foreach ($parts as $part) {
            if (! is_array($part) || count($part) < 2) {
                continue;
            }

            [$paragraph, $offset] = $part;
            $normalized = self::normalizeWhitespace($paragraph);

            if ($normalized === '') {
                continue;
            }

            $segments[] = [
                'offset' => (int) $offset,
                'text' => $normalized,
            ];
        }

        if (count($segments) > 1) {
            return $segments;
        }

        return self::sentenceSegments($text);
    }

    /**
     * @return array<int, array{offset: int, text: string}>
     */
    private static function sentenceSegments(string $text): array
    {
        $matches = [];
        preg_match_all('/[^.!?]+(?:[.!?]+|$)/u', $text, $matches, PREG_OFFSET_CAPTURE);

        $sentences = $matches[0] ?? [];

        if ($sentences === []) {
            return [[
                'offset' => 0,
                'text' => self::normalizeWhitespace($text),
            ]];
        }

        $segments = [];
        $buffer = '';
        $bufferOffset = null;

        foreach ($sentences as $sentence) {
            if (! is_array($sentence) || count($sentence) < 2) {
                continue;
            }

            [$sentenceText, $offset] = $sentence;
            $sentenceText = self::normalizeWhitespace($sentenceText);

            if ($sentenceText === '') {
                continue;
            }

            $separator = $buffer === '' ? '' : ' ';
            $candidate = $buffer.$separator.$sentenceText;

            if ($buffer !== '' && mb_strlen($candidate) > self::SEGMENT_MAX_CHARS) {
                $segments[] = [
                    'offset' => $bufferOffset ?? 0,
                    'text' => $buffer,
                ];
                $buffer = $sentenceText;
                $bufferOffset = (int) $offset;

                continue;
            }

            if ($buffer === '') {
                $bufferOffset = (int) $offset;
            }

            $buffer = $candidate;

            if (mb_strlen($buffer) >= self::SEGMENT_TARGET_CHARS) {
                $segments[] = [
                    'offset' => $bufferOffset ?? 0,
                    'text' => $buffer,
                ];
                $buffer = '';
                $bufferOffset = null;
            }
        }

        if ($buffer !== '') {
            $segments[] = [
                'offset' => $bufferOffset ?? 0,
                'text' => $buffer,
            ];
        }

        return $segments;
    }

    private static function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function segmentScore(string $text): float
    {
        $wordCount = self::wordCount($text);

        if ($wordCount === 0) {
            return 0.0;
        }

        $normalized = mb_strtolower($text);
        $keywordHits = self::countPhraseMatches($normalized, self::CIVIC_PHRASES);
        $actionHits = self::countPhraseMatches($normalized, self::PROCESS_PHRASES);
        $dateHits = self::countRegexMatches(self::datePattern(), $text);
        $timeHits = self::countRegexMatches(self::timePattern(), $text);
        $urlHits = self::countRegexMatches('/https?:\/\/[^\s)]+/i', $text);
        $labelHits = self::countRegexMatches('/\b[A-Za-z][A-Za-z\s]{0,20}:\s+/', $text);
        $entityHits = self::countRegexMatches('/\b[A-Z][a-z]{2,}(?:\s+[A-Z][a-z]{2,}){1,3}\b/', $text);
        $numberHits = self::countRegexMatches('/\b\d+(?:,\d{3})*(?:\.\d+)?\b/', $text);

        $structuralScore = ($dateHits * 2.0)
            + ($timeHits * 1.5)
            + ($urlHits * 1.0)
            + ($labelHits * 0.8)
            + ($entityHits * 0.6);

        $keywordScore = ($keywordHits * 0.7) + ($actionHits * 0.8);
        $numericScore = min(5, $numberHits) * 0.2;
        $rawScore = $structuralScore + $keywordScore + $numericScore;

        if ($structuralScore === 0.0) {
            $rawScore = ($keywordScore * 0.2) + $numericScore;
        }

        $densityDivider = max(1.0, $wordCount / 60);

        return $rawScore / $densityDivider;
    }

    private static function wordCount(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        return count(array_filter($words, static fn ($word) => $word !== ''));
    }

    /**
     * @param  list<string>  $phrases
     */
    private static function countPhraseMatches(string $text, array $phrases): int
    {
        $count = 0;

        foreach ($phrases as $phrase) {
            $pattern = '/\b'.preg_quote($phrase, '/').'\b/u';
            $count += self::countRegexMatches($pattern, $text);
        }

        return $count;
    }

    private static function countRegexMatches(string $pattern, string $text): int
    {
        $matches = [];
        preg_match_all($pattern, $text, $matches);

        return count($matches[0] ?? []);
    }

    private static function datePattern(): string
    {
        $monthPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';

        return '/\b'.$monthPattern.'\.?\s+\d{1,2}(?:,\s*\d{4})?\b|\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b/i';
    }

    private static function timePattern(): string
    {
        return '/\b\d{1,2}:\d{2}\s?(?:am|pm)\b|\b\d{1,2}\s?(?:am|pm)\b|\b\d{1,2}:\d{2}\b/i';
    }

    /**
     * @param  array<int, array{offset: int, text: string, score: float}>  $scoredSegments
     * @return array<int, array{offset: int, text: string}>
     */
    private static function selectSegments(array $scoredSegments, int $currentLength): array
    {
        $selected = [];
        $separatorLength = mb_strlen(self::SEPARATOR);

        foreach ($scoredSegments as $segment) {
            $segmentText = rtrim($segment['text']);

            if ($segmentText === '') {
                continue;
            }

            $available = self::MAX_CHARS - $currentLength - $separatorLength;

            if ($available <= 0) {
                break;
            }

            if (mb_strlen($segmentText) > $available) {
                $segmentText = rtrim(mb_substr($segmentText, 0, $available));
            }

            if ($segmentText === '') {
                break;
            }

            $selected[] = [
                'offset' => $segment['offset'],
                'text' => $segmentText,
            ];

            $currentLength += $separatorLength + mb_strlen($segmentText);
        }

        return $selected;
    }
}
