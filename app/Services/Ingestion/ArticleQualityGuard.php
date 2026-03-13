<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Arr;

class ArticleQualityGuard
{
    public const REASON_BOT_CHALLENGE = 'bot_challenge';

    public const REASON_BLOCKED_URL_PATH = 'blocked_url_path';

    public const REASON_MIN_CONTENT = 'min_content';

    public const REASON_OPINION_CONTENT = 'opinion_content';

    public const REASON_PROFILE_TITLE = 'profile_title';

    /**
     * @param  array<string, mixed>  $item
     */
    public function rejectionReason(array $item): ?string
    {
        if (! (bool) config('ingestion.quality_guard.enabled', true)) {
            return null;
        }

        $sourceUrl = $this->sourceUrl($item);

        if ($this->containsBotChallengeMarkers($item)) {
            return self::REASON_BOT_CHALLENGE;
        }

        if ($sourceUrl !== null && $this->matchesBlockedUrlPath($sourceUrl)) {
            return self::REASON_BLOCKED_URL_PATH;
        }

        if ($this->isOpinionLikeItem($item, $sourceUrl)) {
            return self::REASON_OPINION_CONTENT;
        }

        if (! $this->isLikelyDocumentItem($item) && $this->isBelowMinimumContent($item)) {
            return self::REASON_MIN_CONTENT;
        }

        if ($this->isLikelyProfileTitleItem($item)) {
            return self::REASON_PROFILE_TITLE;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function knownReasons(): array
    {
        return [
            self::REASON_BOT_CHALLENGE,
            self::REASON_BLOCKED_URL_PATH,
            self::REASON_MIN_CONTENT,
            self::REASON_OPINION_CONTENT,
            self::REASON_PROFILE_TITLE,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isOpinionLikeItem(array $item, ?string $sourceUrl): bool
    {
        if (! (bool) config('ingestion.quality_guard.opinion_guard.enabled', true)) {
            return false;
        }

        if ($sourceUrl !== null && $this->matchesOpinionUrlPath($sourceUrl)) {
            return true;
        }

        $title = Arr::get($item, 'title');

        if (! is_string($title) || trim($title) === '') {
            return false;
        }

        $titlePrefixes = config('ingestion.quality_guard.opinion_guard.title_prefixes', []);

        if (! is_array($titlePrefixes) || $titlePrefixes === []) {
            return false;
        }

        $normalizedTitle = mb_strtolower(trim($title));

        foreach ($titlePrefixes as $prefix) {
            if (! is_string($prefix) || trim($prefix) === '') {
                continue;
            }

            $normalizedPrefix = preg_quote(mb_strtolower(trim($prefix)), '/');

            if (preg_match('/^'.$normalizedPrefix.'(?:\s*[:\-\|]\s*|\s+)/u', $normalizedTitle) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function sourceUrl(array $item): ?string
    {
        $sourceUrl = Arr::get($item, 'source.source_url');

        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            $sourceUrl = Arr::get($item, 'canonical_url');
        }

        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            return null;
        }

        return trim($sourceUrl);
    }

    private function matchesBlockedUrlPath(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $segments = config('ingestion.quality_guard.blocked_url_segments', []);

        if (! is_array($segments) || $segments === []) {
            return false;
        }

        $normalizedPath = '/'.trim(mb_strtolower($path), '/').'/';

        foreach ($segments as $segment) {
            if (! is_string($segment) || trim($segment) === '') {
                continue;
            }

            $normalizedSegment = trim(mb_strtolower($segment));

            if (str_contains($normalizedPath, '/'.$normalizedSegment.'/')) {
                return true;
            }
        }

        return false;
    }

    private function matchesOpinionUrlPath(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $segments = config('ingestion.quality_guard.opinion_guard.url_segments', []);

        if (! is_array($segments) || $segments === []) {
            return false;
        }

        $normalizedPath = '/'.trim(mb_strtolower($path), '/').'/';

        foreach ($segments as $segment) {
            if (! is_string($segment) || trim($segment) === '') {
                continue;
            }

            $normalizedSegment = trim(mb_strtolower($segment));

            if (str_contains($normalizedPath, '/'.$normalizedSegment.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isBelowMinimumContent(array $item): bool
    {
        $content = $this->contentText($item);

        if ($content === null) {
            return false;
        }

        $minWords = max(0, (int) config('ingestion.quality_guard.min_words', 25));
        $minChars = max(0, (int) config('ingestion.quality_guard.min_chars', 180));

        if ($minWords === 0 && $minChars === 0) {
            return false;
        }

        $words = $this->wordCount($content);
        $chars = mb_strlen($content);

        if ($words < $minWords && $chars < $minChars) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isLikelyProfileTitleItem(array $item): bool
    {
        if (! (bool) config('ingestion.quality_guard.profile_title_guard.enabled', true)) {
            return false;
        }

        $title = Arr::get($item, 'title');

        if (! is_string($title) || trim($title) === '') {
            return false;
        }

        $trimmedTitle = trim($title);

        if (! $this->looksLikePersonName($trimmedTitle)) {
            return false;
        }

        $content = $this->contentText($item);

        if ($content === null) {
            return false;
        }

        $maxWords = max(1, (int) config('ingestion.quality_guard.profile_title_guard.max_words', 40));

        if ($this->wordCount($content) > $maxWords) {
            return false;
        }

        $keywords = config('ingestion.quality_guard.profile_title_guard.role_keywords', []);

        if (! is_array($keywords) || $keywords === []) {
            return false;
        }

        $lowerContent = mb_strtolower($content);

        foreach ($keywords as $keyword) {
            if (! is_string($keyword) || trim($keyword) === '') {
                continue;
            }

            if (str_contains($lowerContent, mb_strtolower(trim($keyword)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function contentText(array $item): ?string
    {
        $cleanedText = Arr::get($item, 'body.cleaned_text');
        $summary = Arr::get($item, 'summary');

        foreach ([$cleanedText, $summary] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function wordCount(string $value): int
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return 0;
        }

        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($words)) {
            return 0;
        }

        return count($words);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function containsBotChallengeMarkers(array $item): bool
    {
        $candidates = [
            Arr::get($item, 'title'),
            Arr::get($item, 'summary'),
            Arr::get($item, 'body.cleaned_text'),
            Arr::get($item, 'body.raw_html'),
        ];

        $markers = [
            'px-captcha',
            'access to this page has been denied',
            'before we continue',
            'cf-chl-',
            'checking your browser',
            'javascript required',
            'verify you are human',
            "window['ppconfig']",
            'periodicreportingratemillis',
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $lowerCandidate = mb_strtolower($candidate);

            foreach ($markers as $marker) {
                if (str_contains($lowerCandidate, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isLikelyDocumentItem(array $item): bool
    {
        $contentType = mb_strtolower((string) Arr::get($item, 'content_type', ''));
        $sourceType = mb_strtolower((string) Arr::get($item, 'source.source_type', ''));
        $sourceUrl = mb_strtolower((string) ($this->sourceUrl($item) ?? ''));

        $documentTypes = ['pdf', 'doc', 'docx', 'document'];

        if (in_array($contentType, $documentTypes, true)) {
            return true;
        }

        if (in_array($sourceType, $documentTypes, true)) {
            return true;
        }

        if (preg_match('/\.(pdf|docx?)($|\?)/', $sourceUrl) === 1) {
            return true;
        }

        return str_contains($sourceUrl, 'archive.aspx?adid=');
    }

    private function looksLikePersonName(string $value): bool
    {
        $parts = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts)) {
            return false;
        }

        $count = count($parts);

        if ($count < 2 || $count > 4) {
            return false;
        }

        foreach ($parts as $part) {
            if (preg_match('/^[\p{Lu}][\p{L}]+(?:[-\'][\p{Lu}][\p{L}]+)*$/u', $part) !== 1) {
                return false;
            }
        }

        return true;
    }
}
