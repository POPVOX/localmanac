<?php

namespace App\Services\Analysis;

use App\Models\Article;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class HeuristicScorer
{
    public function __construct(private readonly CivicRelevanceCalculator $calculator) {}

    /**
     * @return array{
     *     dimensions: array<string, float>,
     *     signals: array<string, mixed>,
     *     opportunities: array<int, array<string, mixed>>
     * }
     */
    public function score(Article $article): array
    {
        $article->loadMissing(['body', 'scraper.organization', 'sources']);

        $text = $this->buildText($article);
        $wordCount = $this->wordCount($text);
        $sentenceCount = $this->sentenceCount($text);
        $syllableCount = $this->syllableCount($text);

        $readability = $this->readabilityScore($wordCount, $sentenceCount, $syllableCount);
        $jargonStats = $this->jargonStats($text, $wordCount);
        $comprehensibility = $this->clamp($readability - $this->jargonPenalty($jargonStats['density']));

        $futureDates = $this->extractFutureDates($text);
        $timeliness = $this->timelinessScore($futureDates);

        $agencyMatches = $this->countKeywordMatches($text, $this->agencyKeywords());
        $orientationMatches = $this->countKeywordMatches($text, $this->orientationKeywords());

        $representationSignals = $this->representationSignals($text);
        $relevanceSignals = $this->relevanceSignals($article, $text);

        $agency = $this->agencyScore($agencyMatches, $futureDates !== []);
        $orientation = $this->orientationScore($orientationMatches);
        $representation = $this->representationScore(
            $representationSignals['named_entities'],
            $representationSignals['keyword_matches']
        );
        $relevance = $relevanceSignals['score'];

        $dimensions = $this->calculator->finalScores([
            ScoreDimensions::COMPREHENSIBILITY => $comprehensibility,
            ScoreDimensions::ORIENTATION => $orientation,
            ScoreDimensions::REPRESENTATION => $representation,
            ScoreDimensions::AGENCY => $agency,
            ScoreDimensions::RELEVANCE => $relevance,
            ScoreDimensions::TIMELINESS => $timeliness,
        ]);

        $signals = [
            'word_count' => $wordCount,
            'sentence_count' => $sentenceCount,
            'syllable_count' => $syllableCount,
            'readability' => $readability,
            'jargon_hits' => $jargonStats['hits'],
            'jargon_density' => $jargonStats['density'],
            'future_dates' => array_map(
                static fn (CarbonImmutable $date) => $date->toIso8601String(),
                $futureDates
            ),
            'agency_matches' => $agencyMatches,
            'orientation_matches' => $orientationMatches,
            'representation_named_entities' => $representationSignals['named_entities'],
            'representation_keyword_matches' => $representationSignals['keyword_matches'],
            'relevance_keyword_matches' => $relevanceSignals['keyword_matches'],
            'org_type' => $article->scraper?->organization?->type,
            'urls' => $this->extractUrls($text),
        ];

        return [
            'dimensions' => $dimensions,
            'signals' => $signals,
            'opportunities' => $this->extractOpportunities($article, $text, $futureDates),
        ];
    }

    private function buildText(Article $article): string
    {
        return trim(implode(' ', array_filter([
            $article->title,
            $article->summary,
            $article->body?->cleaned_text,
        ], static fn (?string $value) => is_string($value) && trim($value) !== '')));
    }

    private function wordCount(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        $words = preg_split('/\s+/u', trim($text)) ?: [];

        return count(array_filter($words, static fn ($word) => $word !== ''));
    }

    private function sentenceCount(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        $matches = [];
        preg_match_all('/[.!?]+/', $text, $matches);

        return max(1, count($matches[0] ?? []));
    }

    private function syllableCount(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $count = 0;

        foreach ($words as $word) {
            $count += $this->countSyllables($word);
        }

        return $count;
    }

    private function countSyllables(string $word): int
    {
        $word = strtolower($word);
        $word = preg_replace('/[^a-z]/', '', $word) ?? '';

        if ($word === '') {
            return 0;
        }

        if (strlen($word) <= 3) {
            return 1;
        }

        preg_match_all('/[aeiouy]+/', $word, $matches);
        $count = count($matches[0] ?? []);

        if (str_ends_with($word, 'e') && ! str_ends_with($word, 'le') && $count > 1) {
            $count--;
        }

        return max(1, $count);
    }

    private function readabilityScore(int $wordCount, int $sentenceCount, int $syllableCount): float
    {
        if ($wordCount === 0 || $sentenceCount === 0) {
            return 0.4;
        }

        $wordsPerSentence = $wordCount / $sentenceCount;
        $syllablesPerWord = $wordCount > 0 ? $syllableCount / $wordCount : 0.0;

        $score = 206.835 - (1.015 * $wordsPerSentence) - (84.6 * $syllablesPerWord);

        return $this->clamp($score / 100);
    }

    /**
     * @return array{hits: int, density: float}
     */
    private function jargonStats(string $text, int $wordCount): array
    {
        $terms = config('analysis.jargon', []);
        $hits = 0;

        foreach ($terms as $term) {
            $hits += $this->countPhraseMatches($text, (string) $term);
        }

        $density = $wordCount > 0 ? $hits / $wordCount : 0.0;

        return [
            'hits' => $hits,
            'density' => $density,
        ];
    }

    private function jargonPenalty(float $density): float
    {
        return min(0.5, $density * 2.5);
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function extractFutureDates(string $text): array
    {
        $candidates = [];

        $monthPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
        preg_match_all('/\b'.$monthPattern.'\.?\s+\d{1,2}(?:,\s*\d{4})?\b/i', $text, $matches);
        $candidates = array_merge($candidates, $matches[0] ?? []);

        preg_match_all('/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/', $text, $matches);
        $candidates = array_merge($candidates, $matches[0] ?? []);

        preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $text, $matches);
        $candidates = array_merge($candidates, $matches[0] ?? []);

        $now = CarbonImmutable::now();
        $dates = [];

        foreach (array_unique($candidates) as $candidate) {
            $candidate = trim($candidate);

            try {
                $date = CarbonImmutable::parse($candidate);
            } catch (\Throwable $exception) {
                continue;
            }

            if (! $this->candidateHasYear($candidate)) {
                if ($date->lessThan($now->startOfDay())) {
                    $date = $date->addYear();
                }
            }

            if ($date->greaterThan($now)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    private function candidateHasYear(string $candidate): bool
    {
        return preg_match('/\b\d{4}\b/', $candidate) === 1;
    }

    /**
     * @param  array<int, CarbonImmutable>  $futureDates
     */
    private function timelinessScore(array $futureDates): float
    {
        if ($futureDates === []) {
            return 0.2;
        }

        usort($futureDates, static fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);
        $nextDate = $futureDates[0];
        $days = CarbonImmutable::now()->diffInDays($nextDate, false);

        if ($days <= 7) {
            return 0.9;
        }

        if ($days <= 30) {
            return 0.7;
        }

        return 0.5;
    }

    /**
     * @param  list<string>  $keywords
     */
    private function countKeywordMatches(string $text, array $keywords): int
    {
        $count = 0;

        foreach ($keywords as $keyword) {
            $count += $this->countPhraseMatches($text, $keyword);
        }

        return $count;
    }

    private function countPhraseMatches(string $text, string $phrase): int
    {
        $pattern = preg_quote(mb_strtolower($phrase), '/');
        $pattern = str_replace('\ ', '\s+', $pattern);
        $matches = [];
        preg_match_all('/\b'.$pattern.'\b/i', mb_strtolower($text), $matches);

        return count($matches[0] ?? []);
    }

    private function agencyScore(int $matches, bool $hasFutureDates): float
    {
        $score = match (true) {
            $matches <= 0 => 0.2,
            $matches === 1 => 0.5,
            $matches === 2 => 0.7,
            default => 0.85,
        };

        if ($hasFutureDates) {
            $score += 0.1;
        }

        return $this->clamp($score);
    }

    private function orientationScore(int $matches): float
    {
        $score = match (true) {
            $matches <= 0 => 0.2,
            $matches === 1 => 0.5,
            $matches === 2 => 0.7,
            default => 0.85,
        };

        return $this->clamp($score);
    }

    /**
     * @return array{named_entities: int, keyword_matches: int}
     */
    private function representationSignals(string $text): array
    {
        $matches = [];
        preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+\b/', $text, $matches);
        $namedEntities = count($matches[0] ?? []);

        $keywordMatches = $this->countKeywordMatches($text, $this->representationKeywords());

        return [
            'named_entities' => $namedEntities,
            'keyword_matches' => $keywordMatches,
        ];
    }

    private function representationScore(int $namedEntities, int $keywordMatches): float
    {
        $score = 0.25;

        if ($namedEntities > 0) {
            $score += min(0.5, $namedEntities * 0.1);
        }

        if ($keywordMatches > 0) {
            $score += 0.2;
        }

        return $this->clamp($score);
    }

    /**
     * @return array{score: float, keyword_matches: int}
     */
    private function relevanceSignals(Article $article, string $text): array
    {
        $orgType = $article->scraper?->organization?->type;
        $score = $orgType === 'government' ? 0.65 : 0.4;
        $keywordMatches = $this->countKeywordMatches($text, $this->relevanceKeywords());

        if ($keywordMatches > 0) {
            $score += 0.15;
        }

        return [
            'score' => $this->clamp($score),
            'keyword_matches' => $keywordMatches,
        ];
    }

    /**
     * @param  array<int, CarbonImmutable>  $futureDates
     * @return array<int, array<string, mixed>>
     */
    private function extractOpportunities(Article $article, string $text, array $futureDates): array
    {
        if ($futureDates === []) {
            return [];
        }

        $opportunities = [];
        $url = $article->primarySourceUrl() ?? Arr::first($this->extractUrls($text));
        $nextDate = $this->nearestFutureDate($futureDates);

        if ($this->hasOpportunityKeyword($text, ['public hearing', 'hearing', 'meeting'])) {
            $opportunities[] = [
                'kind' => 'meeting',
                'title' => $article->title ? 'Meeting: '.$article->title : 'Public meeting',
                'starts_at' => $nextDate,
                'url' => $url,
                'notes' => 'Detected meeting language with a future date.',
                'confidence' => 0.55,
            ];
        }

        if ($this->hasOpportunityKeyword($text, ['comment period', 'public comment'])) {
            $opportunities[] = [
                'kind' => 'comment_period',
                'title' => $article->title ? 'Comment period: '.$article->title : 'Comment period',
                'ends_at' => $nextDate,
                'url' => $url,
                'notes' => 'Detected comment period language with a future date.',
                'confidence' => 0.55,
            ];
        }

        if ($this->hasOpportunityKeyword($text, ['submit comments', 'comment deadline', 'deadline'])) {
            $opportunities[] = [
                'kind' => 'deadline',
                'title' => $article->title ? 'Deadline: '.$article->title : 'Deadline',
                'ends_at' => $nextDate,
                'url' => $url,
                'notes' => 'Detected deadline language with a future date.',
                'confidence' => 0.5,
            ];
        }

        return $opportunities;
    }

    /**
     * @param  array<int, CarbonImmutable>  $futureDates
     */
    private function nearestFutureDate(array $futureDates): ?CarbonImmutable
    {
        usort($futureDates, static fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return $futureDates[0] ?? null;
    }

    /**
     * @param  list<string>  $phrases
     */
    private function hasOpportunityKeyword(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($this->countPhraseMatches($text, $phrase) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function agencyKeywords(): array
    {
        return [
            'public hearing',
            'comment period',
            'submit comments',
            'agenda',
            'register',
            'sign up',
            'vote',
            'deadline',
        ];
    }

    /**
     * @return list<string>
     */
    private function orientationKeywords(): array
    {
        return [
            'proposal',
            'ordinance',
            'resolution',
            'agenda item',
            'board',
            'commission',
        ];
    }

    /**
     * @return list<string>
     */
    private function representationKeywords(): array
    {
        return [
            'department',
            'council',
            'board',
            'commission',
            'residents',
            'staff',
            'mayor',
        ];
    }

    /**
     * @return list<string>
     */
    private function relevanceKeywords(): array
    {
        return [
            'city council',
            'county',
            'ordinance',
            'resolution',
            'public hearing',
            'commission',
            'board',
            'budget',
            'zoning',
        ];
    }

    /**
     * @return list<string>
     */
    private function extractUrls(string $text): array
    {
        $matches = [];
        preg_match_all('/https?:\/\/[^\s)]+/i', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
