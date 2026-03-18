<?php

namespace App\Services\Ingestion;

use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\Ingestion\PageFetcher;

class RssCanonicalBodyHydrator
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly HtmlTextExtractor $htmlTextExtractor,
    ) {}

    /**
     * @return array{
     *     canonical_url: string|null,
     *     raw_html: string,
     *     raw_text: string,
     *     cleaned_text: string,
     *     title: string|null,
     *     renderer: string|null
     * }|null
     */
    public function hydrate(string $url): ?array
    {
        $result = $this->pageFetcher->fetch($url);

        if (! is_array($result) || ! is_string($result['body'] ?? null)) {
            return null;
        }

        $html = trim((string) $result['body']);

        if (! $this->isHydratableResponse($result['content_type'] ?? null, $html)) {
            return null;
        }

        try {
            $extracted = $this->htmlTextExtractor->extract($html, $url);
        } catch (\Throwable) {
            return null;
        }

        $cleanedText = trim((string) ($extracted['text'] ?? ''));

        if ($cleanedText === '') {
            return null;
        }

        return [
            'canonical_url' => is_string($extracted['canonical_url'] ?? null) ? $extracted['canonical_url'] : null,
            'raw_html' => $html,
            'raw_text' => $cleanedText,
            'cleaned_text' => $cleanedText,
            'title' => is_string($extracted['title'] ?? null) ? $extracted['title'] : null,
            'renderer' => is_string($result['renderer'] ?? null) ? $result['renderer'] : null,
        ];
    }

    public function shouldHydrate(?string $cleanedText, ?string $summary, ?string $contentEncoded, ?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $normalizedText = $this->normalize($cleanedText);
        $normalizedSummary = $this->normalize($summary);
        $normalizedContent = $this->normalize($contentEncoded);

        if ($normalizedContent !== null && mb_strlen($normalizedContent) >= 280) {
            return false;
        }

        if ($normalizedText === null) {
            return true;
        }

        if ($normalizedSummary !== null && $normalizedText === $normalizedSummary) {
            return true;
        }

        return mb_strlen($normalizedText) < 280;
    }

    private function isHydratableResponse(?string $contentType, string $body): bool
    {
        if ($body === '' || ! mb_check_encoding($body, 'UTF-8')) {
            return false;
        }

        $lowerBody = mb_strtolower(ltrim($body));

        foreach (['<!doctype html', '<html', '<head', '<body', '<main', '<article', '<div'] as $marker) {
            if (str_contains($lowerBody, $marker)) {
                return true;
            }
        }

        $normalizedContentType = mb_strtolower(trim((string) $contentType));

        return str_starts_with($normalizedContentType, 'text/html')
            || str_starts_with($normalizedContentType, 'application/xhtml+xml');
    }

    private function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }
}
