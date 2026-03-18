<?php

namespace App\Services\Chat\Ingestion;

use App\Services\Chat\HtmlTextExtractor;

class PageFetcher
{
    public function __construct(
        private readonly HttpPageFetcher $httpFetcher,
        private readonly PlaywrightPageFetcher $playwrightFetcher,
        private readonly HtmlTextExtractor $htmlTextExtractor,
    ) {}

    /**
     * @return array{url: string, status_code: int, content_type: string|null, body: string, renderer: string}|null
     */
    public function fetch(string $url, ?string $rendererOverride = null, array $playwrightOptions = []): ?array
    {
        $mode = $rendererOverride && $rendererOverride !== ''
            ? $rendererOverride
            : (string) config('chat.crawl_renderer', 'auto');

        if ($mode === 'playwright') {
            return $this->playwrightFetch($url, $playwrightOptions) ?? $this->httpFetcher->fetch($url);
        }

        if ($mode === 'http') {
            return $this->httpFetcher->fetch($url);
        }

        $httpResult = $this->httpFetcher->fetch($url);

        if ($httpResult === null) {
            return $this->playwrightFetch($url, $playwrightOptions);
        }

        if (! $this->isProcessableResponse($httpResult['content_type'] ?? null, $httpResult['body'])) {
            return null;
        }

        if ($this->shouldUsePlaywright($httpResult['body'], $url)) {
            $playwrightResult = $this->playwrightFetch($url, $playwrightOptions);

            if ($playwrightResult !== null) {
                return $playwrightResult;
            }
        }

        return $httpResult;
    }

    private function shouldUsePlaywright(string $html, string $url): bool
    {
        $text = $this->htmlTextExtractor->extract($html, $url)['text'] ?? '';
        $minChars = (int) config('chat.crawl_min_text_chars', 800);

        if (mb_strlen($text) < $minChars) {
            return $this->isLikelyJavascriptShell($html);
        }

        return false;
    }

    private function isProcessableResponse(?string $contentType, string $body): bool
    {
        $normalizedContentType = mb_strtolower(trim((string) $contentType));
        $trimmedBody = ltrim($body);

        if ($trimmedBody === '') {
            return false;
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            return false;
        }

        if ($this->looksLikeHtml($trimmedBody)) {
            return true;
        }

        if ($normalizedContentType === '') {
            return false;
        }

        if (str_starts_with($normalizedContentType, 'text/html')) {
            return true;
        }

        return str_starts_with($normalizedContentType, 'application/xhtml+xml');
    }

    private function looksLikeHtml(string $body): bool
    {
        $lower = mb_strtolower($body);

        foreach (['<!doctype html', '<html', '<head', '<body', '<main', '<article', '<div'] as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isLikelyJavascriptShell(string $html): bool
    {
        $lower = mb_strtolower($html);
        $minHtmlChars = (int) config('chat.playwright_min_html_chars', 20000);

        if (mb_strlen($html) < $minHtmlChars) {
            return true;
        }

        $markers = [
            'id="__next"',
            'data-reactroot',
            'window.__nuxt__',
            'id="app"',
            'data-vue',
            'ng-version',
            'app-root',
            'enable javascript',
            'please enable javascript',
            'javascript required',
            'checking your browser',
        ];

        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $playwrightOptions
     * @return array{url: string, status_code: int, content_type: string|null, body: string, renderer: string}|null
     */
    private function playwrightFetch(string $url, array $playwrightOptions): ?array
    {
        if ($playwrightOptions === []) {
            return $this->playwrightFetcher->fetch($url);
        }

        return $this->playwrightFetcher->fetch($url, $playwrightOptions);
    }
}
