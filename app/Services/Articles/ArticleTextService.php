<?php

namespace App\Services\Articles;

use App\Models\Article;
use Illuminate\Support\Str;

class ArticleTextService
{
    public function __construct(
        private readonly ?MeetingSummaryFallback $meetingSummaryFallback = null,
    ) {}

    public function refresh(Article $article, ?string $cleanedText = null, ?string $whatsHappening = null): bool
    {
        $article->loadMissing(['body', 'explainer']);

        $currentTitle = $this->stringValue($article->title);
        $currentSummary = $this->stringValue($article->summary);

        $cleanedText = $this->stringValue($cleanedText) ?? $this->stringValue($article->body?->cleaned_text);
        $whatsHappening = $this->stringValue($whatsHappening) ?? $this->stringValue($article->explainer?->whats_happening);
        $headline = $this->headlineFromPayload($article->explainer?->source_payload);
        $narrative = $this->meetingSummaryFallback()->narrative(
            title: $currentTitle,
            cleanedText: $cleanedText,
            whatsHappening: $whatsHappening,
            whyItMatters: null,
        );
        $whatsHappening = $this->stringValue($narrative['whats_happening']) ?? $whatsHappening;

        $summary = $currentSummary;

        if ($this->summaryNeedsRefresh($currentSummary)) {
            $candidate = $this->buildSummary($whatsHappening, $cleanedText);

            if ($candidate !== null) {
                $summary = $candidate;
            }
        }

        $normalizedTitle = $currentTitle ? $this->normalizeTitle($currentTitle) : null;
        $title = $normalizedTitle ?: $currentTitle;

        if ($title === null || $this->titleNeedsRefresh($title)) {
            if ($headline !== null) {
                $title = $headline;
            } else {
                $titleSource = $this->stringValue($summary)
                    ?? $this->stringValue($whatsHappening);

                if ($titleSource === null || $this->isWeakTitleSource($titleSource)) {
                    $titleSource = $this->stringValue($whatsHappening)
                        ?? $cleanedText
                        ?? $titleSource;
                }

                $derivedTitle = $this->titleFromText($titleSource);

                if ($derivedTitle !== null) {
                    $title = $derivedTitle;
                }
            }
        }

        $dirty = false;

        if ($summary !== $currentSummary) {
            $article->summary = $summary;
            $dirty = true;
        }

        if ($title !== null && $title !== $currentTitle) {
            $article->title = $title;
            $dirty = true;
        }

        if ($dirty) {
            $article->save();
        }

        return $dirty;
    }

    private function buildSummary(?string $whatsHappening, ?string $cleanedText): ?string
    {
        $maxChars = 320;

        $summary = $this->summarizeText($whatsHappening, $maxChars);

        if ($summary !== null) {
            return $summary;
        }

        return $this->summarizeText($cleanedText, $maxChars);
    }

    private function summarizeText(?string $text, int $maxChars): ?string
    {
        $text = $this->stringValue($text);

        if ($text === null) {
            return null;
        }

        $text = $this->normalizeWhitespace($text);

        if ($text === '') {
            return null;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $summary = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            $candidate = $summary === '' ? $sentence : $summary.' '.$sentence;

            if (mb_strlen($candidate) <= $maxChars) {
                $summary = $candidate;

                continue;
            }

            if ($summary === '') {
                return $this->trimToLength($sentence, $maxChars);
            }

            break;
        }

        if ($summary === '') {
            $summary = $this->trimToLength($text, $maxChars);
        }

        return $summary;
    }

    private function titleFromText(?string $text): ?string
    {
        $text = $this->stringValue($text);

        if ($text === null) {
            return null;
        }

        $documentHeadline = $this->extractDocumentHeadline($text);

        if ($documentHeadline !== null) {
            return $documentHeadline;
        }

        $text = $this->normalizeWhitespace($text);

        if ($text === '') {
            return null;
        }

        $sentence = $this->firstSentence($text) ?? $text;
        $sentence = $this->trimSentenceEnd($sentence);

        if ($sentence === '') {
            return null;
        }

        $sentence = $this->headlineFromSentence($sentence);

        if ($sentence === '') {
            return null;
        }

        if (mb_strlen($sentence) < 6) {
            return null;
        }

        return $sentence;
    }

    private function summaryNeedsRefresh(?string $summary): bool
    {
        $summary = $this->stringValue($summary);

        if ($summary === null) {
            return true;
        }

        if ($this->isWeakSummary($summary)) {
            return true;
        }

        if ($this->isLikelyTruncated($summary)) {
            return true;
        }

        return false;
    }

    private function titleNeedsRefresh(string $title): bool
    {
        $title = $this->normalizeWhitespace($title);
        $length = mb_strlen($title);

        if ($length < 4) {
            return true;
        }

        $digits = preg_match_all('/\d/', $title) ?: 0;
        $letters = preg_match_all('/[A-Za-z]/', $title) ?: 0;
        $total = $digits + $letters;

        if ($letters < 3) {
            return true;
        }

        if ($total > 0 && ($digits / $total) > 0.60) {
            return true;
        }

        if ($length >= 80 && preg_match('/\b(is|are|was|were|will)\b/i', $title)) {
            return true;
        }

        if (preg_match('/^the city of\s+/i', $title) && $length > 40) {
            return true;
        }

        foreach ($this->weakTitlePatterns() as $pattern) {
            $subject = $pattern === '/^[A-Z0-9._-]{10,}$/' ? str_replace(' ', '', $title) : $title;

            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTitle(string $title): string
    {
        $normalized = trim($title);
        $normalized = str_replace('_', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*\((pdf|docx?|txt)\)\s*$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*\((pdf|docx?|txt)\)\.\d+\s*$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\.(pdf|docx?|txt)$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\.(pdf|docx?|txt)\.\d+$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+-\s*pdf$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+pdf$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+\.\d+$/', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function headlineFromSentence(string $sentence): string
    {
        $sentence = $this->normalizeWhitespace($sentence);
        $sentence = trim($sentence, "\"'“”‘’");
        $sentence = rtrim($sentence, '.!?');

        $sentence = preg_replace('/^the city of ([^\\s,]+)\\s+is\\s+/i', 'City of $1 ', $sentence) ?? $sentence;
        $sentence = preg_replace('/^the city of ([^\\s,]+)\\s+/i', 'City of $1 ', $sentence) ?? $sentence;
        $sentence = preg_replace('/^the city is\\s+/i', 'City ', $sentence) ?? $sentence;
        $sentence = preg_replace('/^the city\\s+/i', 'City ', $sentence) ?? $sentence;
        $sentence = preg_replace('/^the\\s+/i', '', $sentence) ?? $sentence;

        $replacements = [
            '/\\bis inviting\\b/i' => 'invites',
            '/\\bis seeking\\b/i' => 'seeks',
            '/\\bis requesting\\b/i' => 'requests',
            '/\\bis accepting\\b/i' => 'accepts',
            '/\\bis soliciting\\b/i' => 'solicits',
            '/\\bis calling for\\b/i' => 'calls for',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $sentence = preg_replace($pattern, $replacement, $sentence) ?? $sentence;
        }

        $sentence = preg_replace('/^City( of [^\s,]+)? seeking\b/i', 'City$1 seeks', $sentence) ?? $sentence;
        $sentence = preg_replace('/^City( of [^\s,]+)? inviting\b/i', 'City$1 invites', $sentence) ?? $sentence;
        $sentence = preg_replace('/^City( of [^\s,]+)? requesting\b/i', 'City$1 requests', $sentence) ?? $sentence;
        $sentence = preg_replace('/^City( of [^\s,]+)? accepting\b/i', 'City$1 accepts', $sentence) ?? $sentence;
        $sentence = preg_replace('/^City( of [^\s,]+)? soliciting\b/i', 'City$1 solicits', $sentence) ?? $sentence;
        $sentence = preg_replace('/^City( of [^\s,]+)? calling for\b/i', 'City$1 calls for', $sentence) ?? $sentence;

        $sentence = preg_replace('/\\bfor (the )?project to serve\\b/i', 'for ', $sentence) ?? $sentence;
        $sentence = preg_replace('/\\bproject to serve\\b/i', 'project for', $sentence) ?? $sentence;

        $sentence = $this->trimAfterDelimiter($sentence, ',');

        if (stripos($sentence, ' with ') !== false && mb_strlen($sentence) > 50) {
            $sentence = $this->trimAfterPhrase($sentence, ' with ');
        }

        if (mb_strlen($sentence) > 90) {
            $sentence = $this->trimAfterPhrase($sentence, ' for ');
        }

        $sentence = $this->trimToLength($sentence, 90);

        return trim($sentence);
    }

    private function headlineFromPayload(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $headline = $this->stringValue($payload['headline'] ?? null);

        if ($headline === null) {
            return null;
        }

        $headline = $this->headlineFromSentence($headline);

        if ($headline === '' || mb_strlen($headline) < 10) {
            return null;
        }

        return $headline;
    }

    private function trimAfterDelimiter(string $text, string $delimiter): string
    {
        $position = strpos($text, $delimiter);

        if ($position === false || $position < 20) {
            return $text;
        }

        return trim(substr($text, 0, $position));
    }

    private function trimAfterPhrase(string $text, string $phrase): string
    {
        $position = stripos($text, $phrase);

        if ($position === false || $position < 20) {
            return $text;
        }

        return trim(substr($text, 0, $position));
    }

    private function isWeakTitleSource(string $source): bool
    {
        foreach ($this->weakTitleSourcePatterns() as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function weakTitlePatterns(): array
    {
        $patterns = config('articles.text_refresh.weak_title_patterns', []);

        return is_array($patterns) ? array_values(array_filter($patterns, 'is_string')) : [];
    }

    /**
     * @return array<int, string>
     */
    private function weakTitleSourcePatterns(): array
    {
        $patterns = config('articles.text_refresh.weak_title_source_patterns', []);

        return is_array($patterns) ? array_values(array_filter($patterns, 'is_string')) : [];
    }

    private function extractDocumentHeadline(string $text): ?string
    {
        $lines = $this->linesFromText($text);

        if ($lines === []) {
            return null;
        }

        $projectHeadline = $this->headlineFromProjectSection($lines);

        if ($projectHeadline !== null) {
            return $projectHeadline;
        }

        foreach ($lines as $line) {
            if ($this->isBoilerplateLine($line)) {
                continue;
            }

            if (preg_match('/^abatement of the property\b/i', $line) === 1) {
                $line = $this->trimAfterPhrase($line, ' remove ');
                $headline = $this->documentHeadlineFromLine($line);

                if ($headline !== null) {
                    return $headline;
                }
            }

            if (preg_match('/^notice of public hearing\b/i', $line) === 1) {
                $headline = $this->documentHeadlineFromLine($line);

                if ($headline !== null) {
                    return $headline;
                }
            }
        }

        foreach ($lines as $line) {
            if ($this->isBoilerplateLine($line) || $this->isCodeOnlyLine($line)) {
                continue;
            }

            if (mb_strlen($line) < 10) {
                continue;
            }

            $headline = $this->documentHeadlineFromLine($line);

            if ($headline !== null) {
                return $headline;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function headlineFromProjectSection(array $lines): ?string
    {
        foreach ($lines as $index => $line) {
            $projectMarker = stripos($line, 'for the following project:');

            if ($projectMarker === false) {
                continue;
            }

            $inlineProject = trim(substr($line, $projectMarker + mb_strlen('for the following project:')));

            if ($inlineProject !== '' && ! $this->isCodeOnlyLine($inlineProject)) {
                $inlineHeadline = $this->documentHeadlineFromLine($inlineProject);

                if ($inlineHeadline !== null && mb_strlen($inlineHeadline) >= 10) {
                    return $inlineHeadline;
                }
            }

            $candidate = null;

            for ($offset = 1; $offset <= 4; $offset++) {
                $nextLine = $lines[$index + $offset] ?? null;

                if ($nextLine === null) {
                    break;
                }

                if ($this->isBoilerplateLine($nextLine) || $this->isCodeOnlyLine($nextLine)) {
                    continue;
                }

                $candidate = $nextLine;
                break;
            }

            if ($candidate === null) {
                continue;
            }

            $headline = $this->documentHeadlineFromLine($candidate);

            if ($headline !== null && mb_strlen($headline) >= 10) {
                return $headline;
            }
        }

        return null;
    }

    private function documentHeadlineFromLine(string $line): ?string
    {
        $candidate = $this->normalizeWhitespace($line);

        if ($candidate === '') {
            return null;
        }

        $wasShouting = $this->isMostlyUppercase($candidate);
        $candidate = $this->applyDocumentHeadlineRewriteRules($candidate);

        if ($this->isRejectedDocumentHeadline($candidate)) {
            return null;
        }

        $headline = $this->headlineFromSentence($candidate);

        if ($wasShouting) {
            $headline = Str::headline(mb_strtolower($headline));
        } else {
            $headline = $this->normalizeShoutingHeadline($headline);
        }

        if ($headline === '' || mb_strlen($headline) < 10) {
            return null;
        }

        return $headline;
    }

    /**
     * @return array<int, string>
     */
    private function linesFromText(string $text): array
    {
        $normalized = preg_replace("/\r\n?/", "\n", $text) ?? '';
        $parts = preg_split("/\n+/", $normalized) ?: [];
        $lines = [];

        foreach ($parts as $part) {
            $line = $this->normalizeWhitespace($part);

            if ($line === '') {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function isBoilerplateLine(string $line): bool
    {
        $patterns = [
            '/^proj\s*#/i',
            '/^munis\s*#:/i',
            '/published on the city\'?s website/i',
            '/^sealed proposals$/i',
            '/^notice is hereby given/i',
            '/^all bids received/i',
            '/^dated at wichita/i',
            '/^this information and any addenda/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function applyDocumentHeadlineRewriteRules(string $candidate): string
    {
        $rules = config('articles.text_refresh.document_headline.rewrite_rules', []);

        if (! is_array($rules)) {
            return $candidate;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $pattern = $rule['pattern'] ?? null;
            $replacement = $rule['replacement'] ?? null;

            if (! is_string($pattern) || ! is_string($replacement) || trim($pattern) === '') {
                continue;
            }

            $rewritten = preg_replace($pattern, $replacement, $candidate);

            if (is_string($rewritten) && $rewritten !== $candidate) {
                $candidate = $rewritten;
            }
        }

        return $candidate;
    }

    private function isRejectedDocumentHeadline(string $candidate): bool
    {
        $patterns = config('articles.text_refresh.document_headline.reject_patterns', []);

        if (! is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            if (preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeShoutingHeadline(string $headline): string
    {
        if ($this->isMostlyUppercase($headline)) {
            return Str::headline(mb_strtolower($headline));
        }

        return $headline;
    }

    private function isCodeOnlyLine(string $line): bool
    {
        $compact = str_replace(' ', '', $line);

        if ($compact === '' || preg_match('/^[\dA-Z.\-\/#]+$/', $compact) !== 1) {
            return false;
        }

        $tokens = preg_split('/\s+/', trim($line)) ?: [];
        $digitCount = preg_match_all('/\d/', $compact) ?: 0;
        $hasSeparator = preg_match('/[#\/.-]/', $compact) === 1;

        if ($digitCount === 0 && ! $hasSeparator && count($tokens) >= 4) {
            return false;
        }

        return true;
    }

    private function isMostlyUppercase(string $text): bool
    {
        $letters = preg_match_all('/[A-Za-z]/', $text) ?: 0;
        $uppercase = preg_match_all('/[A-Z]/', $text) ?: 0;

        return $letters >= 10 && $uppercase / max(1, $letters) > 0.7;
    }

    private function isLikelyTruncated(string $summary): bool
    {
        $summary = trim($summary);

        if ($summary === '') {
            return true;
        }

        if (preg_match('/\.{3,}$/', $summary)) {
            return true;
        }

        if (preg_match('/[:;]$/', $summary)) {
            return true;
        }

        if (mb_strlen($summary) >= 180 && ! preg_match('/[.!?]["\')\]]?$/', $summary)) {
            return true;
        }

        return false;
    }

    private function isWeakSummary(string $summary): bool
    {
        $summary = mb_strtolower(trim($summary));

        foreach ([
            'various items were discussed',
            'important local issues affecting',
            'community issues and opportunities',
            'focus on important local issues',
            'residents should be aware of these discussions',
            'helps residents stay informed about community decisions and local governance',
        ] as $phrase) {
            if (str_contains($summary, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function meetingSummaryFallback(): MeetingSummaryFallback
    {
        return $this->meetingSummaryFallback ?? app(MeetingSummaryFallback::class);
    }

    private function normalizeWhitespace(string $text): string
    {
        return Str::squish($text);
    }

    private function trimToLength(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return trim($text);
        }

        $slice = mb_substr($text, 0, $maxChars);
        $trimmed = preg_replace('/\s+\S*$/u', '', $slice) ?? $slice;
        $trimmed = trim($trimmed);

        if ($trimmed === '') {
            return trim($slice);
        }

        return $trimmed;
    }

    private function firstSentence(string $text): ?string
    {
        $matches = [];

        if (preg_match('/^(.+?[.!?])\s/u', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function trimSentenceEnd(string $text): string
    {
        return rtrim($text, " \t\n\r\0\x0B.!?");
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
