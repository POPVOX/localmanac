<?php

namespace App\Services\Extraction;

final class EvidencePackBuilder
{
    private const DEFAULT_MAX_CHARS = 3500;

    private const OPENING_CHARS = 1000;

    private const TAIL_CHARS = 700;

    private const DELIMITER_FORMAT = "\n\n--- [%s] ---\n\n";

    /**
     * @return array{
     *   date_clusters: int,
     *   contact_clusters: int,
     *   heading_clusters: int,
     *   window_before: int,
     *   window_after: int,
     *   heading_lines: int
     * }
     */
    private function normalConfig(): array
    {
        return [
            'date_clusters' => 3,
            'contact_clusters' => 2,
            'heading_clusters' => 2,
            'window_before' => 240,
            'window_after' => 340,
            'heading_lines' => 8,
        ];
    }

    /**
     * @return array{
     *   date_clusters: int,
     *   contact_clusters: int,
     *   heading_clusters: int,
     *   window_before: int,
     *   window_after: int,
     *   heading_lines: int
     * }
     */
    private function rebuildConfig(): array
    {
        return [
            'date_clusters' => 5,
            'contact_clusters' => 3,
            'heading_clusters' => 3,
            'window_before' => 350,
            'window_after' => 450,
            'heading_lines' => 10,
        ];
    }

    public function build(string $text, int $maxChars = self::DEFAULT_MAX_CHARS): EvidencePackResult
    {
        $text = trim($text);
        $originalLength = strlen($text);

        if ($text === '') {
            $signals = $this->emptySignals();

            return new EvidencePackResult('', 0, 0, $signals, $signals, false, []);
        }

        $signalsFull = $this->detectSignals($text);

        if ($originalLength <= $maxChars) {
            return new EvidencePackResult(
                $text,
                $originalLength,
                $originalLength,
                $signalsFull,
                $signalsFull,
                false,
                [[
                    'type' => 'full',
                    'start' => 0,
                    'end' => $originalLength,
                    'why' => 'full_text',
                ]]
            );
        }

        [$packText, $slices] = $this->buildPack($text, $maxChars, $this->normalConfig());
        $signalsPack = $this->detectSignals($packText);
        $rebuildUsed = false;

        if ($this->shouldRebuild($signalsFull, $signalsPack)) {
            [$packText, $slices] = $this->buildPack($text, $maxChars, $this->rebuildConfig(), [
                'require_time' => $signalsFull['time_like_count'] > 0,
            ]);
            $signalsPack = $this->detectSignals($packText);
            $rebuildUsed = true;
        }

        return new EvidencePackResult(
            $packText,
            $originalLength,
            strlen($packText),
            $signalsFull,
            $signalsPack,
            $rebuildUsed,
            $slices
        );
    }

    /**
     * @param  array{
     *   date_clusters: int,
     *   contact_clusters: int,
     *   heading_clusters: int,
     *   window_before: int,
     *   window_after: int,
     *   heading_lines: int
     * }  $config
     * @return array{0: string, 1: array<int, array{type: string, start: int, end: int, score?: float, why?: string}>}
     */
    private function buildPack(string $text, int $maxChars, array $config, array $requirements = []): array
    {
        $textLength = strlen($text);
        $slices = [];
        $requireTime = (bool) ($requirements['require_time'] ?? false);

        $openingEnd = $this->openingEnd($text, $textLength);
        $this->addSlice($slices, [
            'type' => 'opening',
            'start' => 0,
            'end' => $openingEnd,
            'score' => 0.0,
            'why' => 'opening',
        ]);

        $dateCandidates = $this->dateTimeCandidates($text, $config);
        foreach ($this->selectDateTimeSlices($dateCandidates, $config['date_clusters'], $requireTime) as $candidate) {
            $this->addSlice($slices, $candidate);
        }

        $contactCandidates = $this->contactCandidates($text, $config);
        foreach ($this->selectTopSlices($contactCandidates, $config['contact_clusters']) as $candidate) {
            $this->addSlice($slices, $candidate);
        }

        $headingCandidates = $this->headingCandidates($text, $config);
        foreach ($this->selectTopSlices($headingCandidates, $config['heading_clusters']) as $candidate) {
            $this->addSlice($slices, $candidate);
        }

        $tailStart = max(0, $textLength - self::TAIL_CHARS);
        $this->addSlice($slices, [
            'type' => 'tail',
            'start' => $tailStart,
            'end' => $textLength,
            'score' => 0.0,
            'why' => 'tail',
        ]);

        return $this->assemblePack($text, $slices, $maxChars);
    }

    private function openingEnd(string $text, int $textLength): int
    {
        $openingEnd = min(self::OPENING_CHARS, $textLength);
        $boundary = $this->firstBlankLineBoundary($text);

        if ($boundary !== null && $boundary < $openingEnd) {
            $openingEnd = $boundary;
        }

        return max(0, $openingEnd);
    }

    private function firstBlankLineBoundary(string $text): ?int
    {
        $matches = [];
        preg_match('/\R\R/', $text, $matches, PREG_OFFSET_CAPTURE);

        if (! isset($matches[0][1])) {
            return null;
        }

        return (int) $matches[0][1] + strlen((string) $matches[0][0]);
    }

    /**
     * @param  array<int, array{type: string, start: int, end: int, score?: float, why?: string}>  $slices
     */
    private function addSlice(array &$slices, array $candidate): void
    {
        $candidateLength = $candidate['end'] - $candidate['start'];

        if ($candidateLength <= 0) {
            return;
        }

        foreach ($slices as &$existing) {
            $overlap = $this->overlapLength($existing, $candidate);

            if ($overlap === 0) {
                continue;
            }

            if ($overlap / $candidateLength > 0.5) {
                return;
            }

            $existing['start'] = min($existing['start'], $candidate['start']);
            $existing['end'] = max($existing['end'], $candidate['end']);
            $existing['why'] = trim(($existing['why'] ?? '').'; merged '.$candidate['type'], '; ');

            return;
        }

        $slices[] = $candidate;
    }

    /**
     * @param  array{start: int, end: int}  $left
     * @param  array{start: int, end: int}  $right
     */
    private function overlapLength(array $left, array $right): int
    {
        $start = max($left['start'], $right['start']);
        $end = min($left['end'], $right['end']);

        return max(0, $end - $start);
    }

    /**
     * @param  array<int, array{type: string, start: int, end: int, score?: float, why?: string}>  $candidates
     * @return array<int, array{type: string, start: int, end: int, score?: float, why?: string}>
     */
    private function selectTopSlices(array $candidates, int $limit): array
    {
        if ($candidates === [] || $limit <= 0) {
            return [];
        }

        usort($candidates, static function (array $left, array $right): int {
            $leftScore = $left['score'] ?? 0.0;
            $rightScore = $right['score'] ?? 0.0;

            $scoreComparison = $rightScore <=> $leftScore;

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $left['start'] <=> $right['start'];
        });

        $selected = array_slice($candidates, 0, $limit);

        usort($selected, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        return $selected;
    }

    /**
     * @param  array<int, array{type: string, start: int, end: int, score?: float, why?: string, has_time?: bool}>  $candidates
     * @return array<int, array{type: string, start: int, end: int, score?: float, why?: string}>
     */
    private function selectDateTimeSlices(array $candidates, int $limit, bool $requireTime): array
    {
        $selected = $this->selectTopSlices($candidates, $limit);

        if (! $requireTime) {
            return array_map(static function (array $candidate): array {
                unset($candidate['has_time']);

                return $candidate;
            }, $selected);
        }

        $hasTime = false;
        foreach ($selected as $candidate) {
            if (($candidate['has_time'] ?? false) === true) {
                $hasTime = true;
                break;
            }
        }

        if (! $hasTime) {
            $timeCandidates = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => ($candidate['has_time'] ?? false) === true
            ));

            if ($timeCandidates !== []) {
                usort($timeCandidates, static function (array $left, array $right): int {
                    $leftScore = $left['score'] ?? 0.0;
                    $rightScore = $right['score'] ?? 0.0;

                    $scoreComparison = $rightScore <=> $leftScore;

                    if ($scoreComparison !== 0) {
                        return $scoreComparison;
                    }

                    return $left['start'] <=> $right['start'];
                });

                $timeCandidate = $timeCandidates[0];

                if (count($selected) < $limit) {
                    $selected[] = $timeCandidate;
                } elseif ($selected !== []) {
                    usort($selected, static function (array $left, array $right): int {
                        $leftScore = $left['score'] ?? 0.0;
                        $rightScore = $right['score'] ?? 0.0;

                        $scoreComparison = $leftScore <=> $rightScore;

                        if ($scoreComparison !== 0) {
                            return $scoreComparison;
                        }

                        return $right['start'] <=> $left['start'];
                    });

                    $selected[0] = $timeCandidate;
                }
            }
        }

        usort($selected, static function (array $left, array $right): int {
            $leftHasTime = ($left['has_time'] ?? false) ? 1 : 0;
            $rightHasTime = ($right['has_time'] ?? false) ? 1 : 0;

            if ($leftHasTime !== $rightHasTime) {
                return $rightHasTime <=> $leftHasTime;
            }

            return $left['start'] <=> $right['start'];
        });

        return array_map(static function (array $candidate): array {
            unset($candidate['has_time']);

            return $candidate;
        }, $selected);
    }

    /**
     * @param  array<int, array{type: string, start: int, end: int, score?: float, why?: string}>  $slices
     * @return array{0: string, 1: array<int, array{type: string, start: int, end: int, score?: float, why?: string}>}
     */
    private function assemblePack(string $text, array $slices, int $maxChars): array
    {
        $packText = '';
        $usedSlices = [];

        foreach ($slices as $slice) {
            $delimiter = sprintf(self::DELIMITER_FORMAT, strtoupper($slice['type']));
            $segmentText = substr($text, $slice['start'], $slice['end'] - $slice['start']);
            $segmentLength = strlen($delimiter) + strlen($segmentText);
            $remaining = $maxChars - strlen($packText);

            if ($remaining <= 0) {
                break;
            }

            if ($segmentLength > $remaining) {
                $availableForText = $remaining - strlen($delimiter);

                if ($availableForText <= 0) {
                    break;
                }

                $segmentText = substr($segmentText, 0, $availableForText);
                $segmentLength = strlen($delimiter) + strlen($segmentText);
                $slice['end'] = $slice['start'] + strlen($segmentText);
            }

            $packText .= $delimiter.$segmentText;
            $usedSlices[] = $slice;
        }

        return [$packText, $usedSlices];
    }

    /**
     * @param  array{
     *   date_clusters: int,
     *   contact_clusters: int,
     *   heading_clusters: int,
     *   window_before: int,
     *   window_after: int,
     *   heading_lines: int
     * }  $config
     * @return array<int, array{type: string, start: int, end: int, score: float, why: string, has_time: bool}>
     */
    private function dateTimeCandidates(string $text, array $config): array
    {
        $matches = $this->dateTimeMatches($text);
        $candidates = [];

        foreach ($matches as $match) {
            $start = max(0, $match['offset'] - $config['window_before']);
            $end = min(strlen($text), $match['offset'] + $match['length'] + $config['window_after']);
            $snippet = substr($text, $start, $end - $start);
            $scoreInfo = $this->scoreDateTimeSnippet($snippet);

            $candidates[] = [
                'type' => 'date_time',
                'start' => $start,
                'end' => $end,
                'score' => $scoreInfo['score'],
                'why' => $scoreInfo['why'],
                'has_time' => $scoreInfo['has_time'],
            ];
        }

        return $candidates;
    }

    /**
     * @return array<int, array{offset: int, length: int}>
     */
    private function dateTimeMatches(string $text): array
    {
        $matches = [];
        $patterns = [
            $this->datePattern(),
            $this->weekdayPattern(),
            $this->timePattern(),
        ];

        foreach ($patterns as $pattern) {
            $found = [];
            preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] ?? [] as $match) {
                $matches[] = [
                    'offset' => (int) $match[1],
                    'length' => strlen((string) $match[0]),
                ];
            }
        }

        $unique = [];
        foreach ($matches as $match) {
            $key = $match['offset'].':'.$match['length'];
            $unique[$key] = $match;
        }

        return array_values($unique);
    }

    /**
     * @return array{score: float, why: string, has_time: bool}
     */
    private function scoreDateTimeSnippet(string $snippet): array
    {
        $lower = strtolower($snippet);
        $score = 1.0;
        $reasons = [];
        $hasTime = $this->hasMatch($this->timePattern(), $snippet);

        if ($hasTime) {
            $score += 2.0;
            $reasons[] = 'time';
        }

        if ($this->hasMatch($this->weekdayPattern(), $snippet)) {
            $score += 1.0;
            $reasons[] = 'weekday';
        }

        $keywordHits = 0;
        foreach (['at', 'by', 'no later than', 'will', 'held', 'conducted', 'meeting'] as $keyword) {
            if (str_contains($lower, $keyword)) {
                $keywordHits++;
            }
        }

        if ($keywordHits > 0) {
            $score += min(5, $keywordHits) * 0.5;
            $reasons[] = 'keywords';
        }

        $penalties = 0;
        foreach (['affidavit', 'notary', 'subscribed and sworn'] as $penalty) {
            if (str_contains($lower, $penalty)) {
                $penalties++;
            }
        }

        if ($penalties > 0) {
            $score -= 2.0;
            $reasons[] = 'boilerplate';
        }

        foreach (['published on', 'publication'] as $penalty) {
            if (str_contains($lower, $penalty)) {
                $score -= 1.0;
                $reasons[] = 'publication';
                break;
            }
        }

        return [
            'score' => $score,
            'why' => $reasons === [] ? 'date' : implode(',', array_unique($reasons)),
            'has_time' => $hasTime,
        ];
    }

    /**
     * @param  array{
     *   date_clusters: int,
     *   contact_clusters: int,
     *   heading_clusters: int,
     *   window_before: int,
     *   window_after: int,
     *   heading_lines: int
     * }  $config
     * @return array<int, array{type: string, start: int, end: int, score: float, why: string}>
     */
    private function contactCandidates(string $text, array $config): array
    {
        $matches = $this->contactMatches($text);
        $candidates = [];

        foreach ($matches as $match) {
            $start = max(0, $match['offset'] - $config['window_before']);
            $end = min(strlen($text), $match['offset'] + $match['length'] + $config['window_after']);
            $snippet = substr($text, $start, $end - $start);
            $score = 0.5;
            $why = [];

            if ($this->hasMatch($this->urlPattern(), $snippet)) {
                $score += 2.0;
                $why[] = 'url';
            }

            if ($this->hasMatch($this->emailPattern(), $snippet)) {
                $score += 2.0;
                $why[] = 'email';
            }

            if ($this->hasMatch($this->phonePattern(), $snippet)) {
                $score += 2.0;
                $why[] = 'phone';
            }

            $candidates[] = [
                'type' => 'contact',
                'start' => $start,
                'end' => $end,
                'score' => $score,
                'why' => $why === [] ? 'contact' : implode(',', array_unique($why)),
            ];
        }

        return $candidates;
    }

    /**
     * @return array<int, array{offset: int, length: int}>
     */
    private function contactMatches(string $text): array
    {
        $matches = [];
        $patterns = [
            $this->urlPattern(),
            $this->emailPattern(),
            $this->phonePattern(),
        ];

        foreach ($patterns as $pattern) {
            $found = [];
            preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] ?? [] as $match) {
                $matches[] = [
                    'offset' => (int) $match[1],
                    'length' => strlen((string) $match[0]),
                ];
            }
        }

        $unique = [];
        foreach ($matches as $match) {
            $key = $match['offset'].':'.$match['length'];
            $unique[$key] = $match;
        }

        return array_values($unique);
    }

    /**
     * @param  array{
     *   date_clusters: int,
     *   contact_clusters: int,
     *   heading_clusters: int,
     *   window_before: int,
     *   window_after: int,
     *   heading_lines: int
     * }  $config
     * @return array<int, array{type: string, start: int, end: int, score: float, why: string}>
     */
    private function headingCandidates(string $text, array $config): array
    {
        $entries = $this->lineEntries($text);
        $candidates = [];
        $lastIndex = count($entries) - 1;

        foreach ($entries as $index => $entry) {
            $line = $entry['text'];
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $prevBlank = $index === 0 ? true : $entries[$index - 1]['blank'];
            $nextBlank = $index === $lastIndex ? true : $entries[$index + 1]['blank'];
            $isHeading = $this->isHeadingLine($trimmed, $prevBlank, $nextBlank);
            $isBullet = $this->isBulletLine($line);

            if (! $isHeading && ! $isBullet) {
                continue;
            }

            $endIndex = min($lastIndex, $index + $config['heading_lines']);
            $candidates[] = [
                'type' => 'heading_list',
                'start' => $entry['start'],
                'end' => $entries[$endIndex]['end'],
                'score' => 1.0 + ($isHeading ? 0.6 : 0.0) + ($isBullet ? 0.6 : 0.0),
                'why' => $isHeading && $isBullet ? 'heading,bullet' : ($isHeading ? 'heading' : 'bullet'),
            ];
        }

        return $candidates;
    }

    /**
     * @return array<int, array{text: string, start: int, end: int, blank: bool}>
     */
    private function lineEntries(string $text): array
    {
        $matches = [];
        preg_match_all('/^.*(?:\R|$)/m', $text, $matches, PREG_OFFSET_CAPTURE);
        $entries = [];

        foreach ($matches[0] ?? [] as $match) {
            $line = (string) $match[0];
            $offset = (int) $match[1];
            $trimmed = rtrim($line, "\r\n");

            if ($trimmed === '' && $line === '') {
                continue;
            }

            $entries[] = [
                'text' => $trimmed,
                'start' => $offset,
                'end' => $offset + strlen($line),
                'blank' => trim($trimmed) === '',
            ];
        }

        return $entries;
    }

    private function isHeadingLine(string $line, bool $prevBlank, bool $nextBlank): bool
    {
        $line = trim($line);

        if ($line === '') {
            return false;
        }

        if (str_ends_with($line, ':')) {
            return true;
        }

        $letters = preg_match('/[A-Z]/', $line) === 1;
        $isAllCaps = $letters && strtoupper($line) === $line && strlen($line) >= 5;

        if ($isAllCaps) {
            return true;
        }

        return ($prevBlank || $nextBlank) && $this->isTitleCase($line);
    }

    private function isTitleCase(string $line): bool
    {
        $words = preg_split('/\s+/', $line) ?: [];
        $words = array_filter($words, static fn (string $word): bool => $word !== '');

        if (count($words) < 2) {
            return false;
        }

        $titleCase = 0;
        foreach ($words as $word) {
            $first = $word[0] ?? '';
            if ($first !== '' && ctype_upper($first)) {
                $titleCase++;
            }
        }

        return ($titleCase / max(1, count($words))) >= 0.6;
    }

    private function isBulletLine(string $line): bool
    {
        return preg_match('/^\s*(?:[-*•]|\d+\.)\s+/', $line) === 1;
    }

    /**
     * @return array{
     *   date_like_count: int,
     *   time_like_count: int,
     *   has_url: bool,
     *   has_email: bool,
     *   has_phone: bool,
     *   heading_like_count: int,
     *   bullet_like_count: int
     * }
     */
    private function detectSignals(string $text): array
    {
        $dateCount = $this->matchCount($this->datePattern(), $text)
            + $this->matchCount($this->weekdayPattern(), $text);
        $timeCount = $this->matchCount($this->timePattern(), $text);
        $hasUrl = $this->hasMatch($this->urlPattern(), $text);
        $hasEmail = $this->hasMatch($this->emailPattern(), $text);
        $hasPhone = $this->hasMatch($this->phonePattern(), $text);

        $headingCount = 0;
        $bulletCount = 0;
        $entries = $this->lineEntries($text);
        $lastIndex = count($entries) - 1;

        foreach ($entries as $index => $entry) {
            $line = $entry['text'];
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $prevBlank = $index === 0 ? true : $entries[$index - 1]['blank'];
            $nextBlank = $index === $lastIndex ? true : $entries[$index + 1]['blank'];

            if ($this->isHeadingLine($trimmed, $prevBlank, $nextBlank)) {
                $headingCount++;
            }

            if ($this->isBulletLine($line)) {
                $bulletCount++;
            }
        }

        return [
            'date_like_count' => $dateCount,
            'time_like_count' => $timeCount,
            'has_url' => $hasUrl,
            'has_email' => $hasEmail,
            'has_phone' => $hasPhone,
            'heading_like_count' => $headingCount,
            'bullet_like_count' => $bulletCount,
        ];
    }

    /**
     * @return array{
     *   date_like_count: int,
     *   time_like_count: int,
     *   has_url: bool,
     *   has_email: bool,
     *   has_phone: bool,
     *   heading_like_count: int,
     *   bullet_like_count: int
     * }
     */
    private function emptySignals(): array
    {
        return [
            'date_like_count' => 0,
            'time_like_count' => 0,
            'has_url' => false,
            'has_email' => false,
            'has_phone' => false,
            'heading_like_count' => 0,
            'bullet_like_count' => 0,
        ];
    }

    /**
     * @param  array{
     *   date_like_count: int,
     *   time_like_count: int,
     *   has_url: bool,
     *   has_email: bool,
     *   has_phone: bool,
     *   heading_like_count: int,
     *   bullet_like_count: int
     * }  $signalsFull
     * @param  array{
     *   date_like_count: int,
     *   time_like_count: int,
     *   has_url: bool,
     *   has_email: bool,
     *   has_phone: bool,
     *   heading_like_count: int,
     *   bullet_like_count: int
     * }  $signalsPack
     */
    private function shouldRebuild(array $signalsFull, array $signalsPack): bool
    {
        $hardFail = false;
        $softFails = 0;

        if ($signalsFull['time_like_count'] > 0 && $signalsPack['time_like_count'] === 0) {
            $hardFail = true;
        }

        if ($signalsFull['date_like_count'] > 0 && $signalsPack['date_like_count'] === 0) {
            $hardFail = true;
        }

        $fullContact = $signalsFull['has_url'] || $signalsFull['has_email'] || $signalsFull['has_phone'];
        $packContact = $signalsPack['has_url'] || $signalsPack['has_email'] || $signalsPack['has_phone'];

        if ($fullContact && ! $packContact) {
            $hardFail = true;
        }

        if ($signalsFull['heading_like_count'] > 0 && $signalsPack['heading_like_count'] === 0) {
            $softFails++;
        }

        if ($signalsFull['bullet_like_count'] > 0 && $signalsPack['bullet_like_count'] === 0) {
            $softFails++;
        }

        return $hardFail || $softFails >= 2;
    }

    private function matchCount(string $pattern, string $text): int
    {
        $matches = [];
        preg_match_all($pattern, $text, $matches);

        return count($matches[0] ?? []);
    }

    private function hasMatch(string $pattern, string $text): bool
    {
        return preg_match($pattern, $text) === 1;
    }

    private function datePattern(): string
    {
        $monthPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';

        return '/\b'.$monthPattern.'\b|\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b/i';
    }

    private function weekdayPattern(): string
    {
        return '/\b(?:mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b/i';
    }

    private function timePattern(): string
    {
        return '/\b\d{1,2}:\d{2}\s?(?:a\.m\.|p\.m\.|am|pm)?\b|\b\d{1,2}\s?(?:a\.m\.|p\.m\.|am|pm)\b/i';
    }

    private function urlPattern(): string
    {
        return '/https?:\/\/[^\s)]+|\bwww\.[^\s)]+/i';
    }

    private function emailPattern(): string
    {
        return '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    }

    private function phonePattern(): string
    {
        return '/\b\+?1?[\s.\-]?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}\b/';
    }
}
