<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\Scraper;
use App\Services\Chat\Ingestion\PageFetcher;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class GenericListingFetcher
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(Scraper $scraper): array
    {
        if ($scraper->type !== 'html') {
            throw new InvalidArgumentException('Scraper type must be html');
        }

        $config = $scraper->config ?? [];
        $profile = Arr::get($config, 'profile');

        if ($profile !== 'generic_listing') {
            throw new InvalidArgumentException('Scraper profile must be generic_listing');
        }

        $sourceUrl = $scraper->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('Scraper source_url must exist');
        }

        $listConfig = Arr::get($config, 'list');
        $articleConfig = Arr::get($config, 'article');

        if (! is_array($listConfig) || ! is_array($articleConfig)) {
            throw new InvalidArgumentException('Scraper list/article config must exist');
        }

        $linkSelector = Arr::get($listConfig, 'link_selector');

        if (! $linkSelector) {
            throw new InvalidArgumentException('Scraper list link_selector must exist');
        }

        $linkAttr = Arr::get($listConfig, 'link_attr', 'href');
        $maxLinks = (int) Arr::get($listConfig, 'max_links', 50);

        $contentSelector = Arr::get($articleConfig, 'content_selector');

        if (! $contentSelector) {
            throw new InvalidArgumentException('Scraper article content_selector must exist');
        }

        $removeSelectors = Arr::get($articleConfig, 'remove_selectors', []);
        $removeSelectors = is_array($removeSelectors) ? $removeSelectors : [];

        $bestEffort = (bool) Arr::get($config, 'best_effort', true);
        $renderer = $this->resolveRenderer(Arr::get($config, 'fetch.renderer'));
        $listingPlaywrightOptions = $this->resolvePlaywrightOptions($config);
        $articlePlaywrightOptions = $this->resolveArticlePlaywrightOptions($listingPlaywrightOptions);

        $listingHtml = $this->fetchPageHtml($sourceUrl, $renderer, true, $listingPlaywrightOptions);
        $maxPages = max(1, (int) Arr::get($listConfig, 'max_pages', 1));
        $paginationSelector = Arr::get($listConfig, 'pagination_selector');
        $paginationAttr = Arr::get($listConfig, 'pagination_attr', 'href');

        $links = $this->extractListingLinks(
            listingHtml: $listingHtml,
            listingUrl: $sourceUrl,
            renderer: $renderer,
            playwrightOptions: $listingPlaywrightOptions,
            linkSelector: $linkSelector,
            linkAttr: $linkAttr,
            maxLinks: $maxLinks,
            maxPages: $maxPages,
            paginationSelector: is_string($paginationSelector) ? $paginationSelector : null,
            paginationAttr: is_string($paginationAttr) && trim($paginationAttr) !== '' ? $paginationAttr : 'href',
        );

        $items = [];
        $blockedArticleCount = 0;
        $accessedAt = now();

        foreach ($links as $link) {
            $url = $link['url'];
            $titleHint = $link['title'] ?? '';

            try {
                $articleHtml = $this->fetchPageHtml($url, $renderer, false, $articlePlaywrightOptions, true);
            } catch (InvalidArgumentException $exception) {
                if ($this->isAntiBotException($exception)) {
                    $blockedArticleCount++;

                    continue;
                }

                throw $exception;
            }

            if ($articleHtml === null) {
                continue;
            }

            $crawler = new Crawler($articleHtml, $url);

            $canonicalUrl = $this->extractCanonicalUrl($crawler, $url);
            $title = $this->extractTitle($crawler);
            $title = $title ?: ($titleHint ?: $canonicalUrl);
            $publishedAt = $this->extractPublishedAt($crawler);
            $metaDescription = $this->extractMetaDescription($crawler);

            [$bodyHtml, $cleanedText] = $this->extractBody($crawler, $contentSelector, $removeSelectors);

            if ($cleanedText === '' && $metaDescription !== '' && $bestEffort) {
                $cleanedText = $metaDescription;
            }

            if ($cleanedText === '') {
                continue;
            }

            $contentType = $this->determineContentType($cleanedText, $bestEffort);

            $items[] = [
                'city_id' => $scraper->city_id,
                'scraper_id' => $scraper->id,
                'title' => $title,
                'published_at' => $publishedAt,
                'summary' => $metaDescription ?: null,
                'content_type' => $contentType,
                'canonical_url' => $canonicalUrl,
                'body' => [
                    'raw_html' => $bodyHtml,
                    'cleaned_text' => $cleanedText,
                ],
                'source' => [
                    'source_type' => 'html',
                    'source_url' => $canonicalUrl,
                    'accessed_at' => $accessedAt,
                ],
                'content_hash' => sha1($cleanedText),
            ];
        }

        if ($items === [] && $blockedArticleCount > 0) {
            throw new InvalidArgumentException('Article pages are blocked by anti-bot protection. Consider using Playwright renderer with a persistent session or a source feed URL.');
        }

        return $items;
    }

    /**
     * @return array<int, array{url: string, title: string}>
     */
    private function extractLinks(string $html, string $baseUrl, string $selector, string $linkAttr, int $maxLinks): array
    {
        $crawler = new Crawler($html, $baseUrl);

        $links = $crawler->filter($selector)->each(function (Crawler $node) use ($linkAttr, $baseUrl) {
            $href = $node->attr($linkAttr) ?? '';
            $resolved = $this->resolveUrl($href, $baseUrl);

            if (! $resolved) {
                return null;
            }

            if ($this->isLikelyProfileUrl($resolved)) {
                return null;
            }

            return [
                'url' => $resolved,
                'title' => $this->normalizeWhitespace($node->text('')),
            ];
        });

        $links = array_values(array_filter($links, fn ($link) => $link !== null));

        $seen = [];
        $deduped = [];

        foreach ($links as $link) {
            if (isset($seen[$link['url']])) {
                continue;
            }

            $seen[$link['url']] = true;
            $deduped[] = $link;

            if ($maxLinks > 0 && count($deduped) >= $maxLinks) {
                break;
            }
        }

        return $deduped;
    }

    /**
     * @param  array<string, mixed>  $playwrightOptions
     * @return array<int, array{url: string, title: string}>
     */
    private function extractListingLinks(
        string $listingHtml,
        string $listingUrl,
        string $renderer,
        array $playwrightOptions,
        string $linkSelector,
        string $linkAttr,
        int $maxLinks,
        int $maxPages,
        ?string $paginationSelector,
        string $paginationAttr,
    ): array {
        $pages = [
            ['url' => $listingUrl, 'html' => $listingHtml],
        ];
        $queuedListingPages = [$listingUrl => true];

        $results = [];
        $seen = [];

        for ($index = 0; $index < count($pages); $index++) {
            if ($index >= $maxPages) {
                break;
            }

            $page = $pages[$index];
            $batch = $this->extractLinks(
                html: $page['html'],
                baseUrl: $page['url'],
                selector: $linkSelector,
                linkAttr: $linkAttr,
                maxLinks: 0,
            );

            foreach ($batch as $link) {
                if (isset($seen[$link['url']])) {
                    continue;
                }

                $seen[$link['url']] = true;
                $results[] = $link;

                if ($maxLinks > 0 && count($results) >= $maxLinks) {
                    return $results;
                }
            }

            if (count($pages) >= $maxPages) {
                continue;
            }

            $paginationUrls = $this->extractPaginationUrls(
                html: $page['html'],
                baseUrl: $page['url'],
                selector: $paginationSelector,
                attr: $paginationAttr,
                maxPages: $maxPages,
            );

            foreach ($paginationUrls as $paginationUrl) {
                if (isset($queuedListingPages[$paginationUrl])) {
                    continue;
                }

                $queuedListingPages[$paginationUrl] = true;

                if (count($pages) >= $maxPages) {
                    break;
                }

                try {
                    $pageHtml = $this->fetchPageHtml($paginationUrl, $renderer, false, $playwrightOptions, true);
                } catch (InvalidArgumentException $exception) {
                    if ($this->isAntiBotException($exception)) {
                        continue;
                    }

                    throw $exception;
                }

                if ($pageHtml === null) {
                    continue;
                }

                $pages[] = [
                    'url' => $paginationUrl,
                    'html' => $pageHtml,
                ];
            }
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    private function extractPaginationUrls(string $html, string $baseUrl, ?string $selector, string $attr, int $maxPages): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $candidateSelectors = [];

        if (is_string($selector) && trim($selector) !== '') {
            $candidateSelectors[] = trim($selector);
        }

        $candidateSelectors = [
            ...$candidateSelectors,
            'a[rel="next"]',
            'a[href*="/page/"]',
        ];

        $urls = [];

        foreach ($candidateSelectors as $candidateSelector) {
            try {
                $nodes = $crawler->filter($candidateSelector);
            } catch (\Throwable) {
                continue;
            }

            if ($nodes->count() === 0) {
                continue;
            }

            $items = $nodes->each(function (Crawler $node) use ($attr, $baseUrl) {
                $href = $node->attr($attr) ?? '';

                return $this->resolveUrl($href, $baseUrl);
            });

            foreach ($items as $item) {
                if (! is_string($item) || trim($item) === '') {
                    continue;
                }

                $resolved = trim($item);

                if ($resolved === $baseUrl || ! $this->isSameHost($resolved, $baseUrl)) {
                    continue;
                }

                $urls[] = $resolved;
            }
        }

        if ($urls === []) {
            $urls = array_merge($urls, $this->extractPaginationUrlsFromHtml($html, $baseUrl));
        }

        $urls = array_values(array_unique($urls));

        if ($maxPages <= 1 || $urls === []) {
            return [];
        }

        return $urls;
    }

    /**
     * @return array<int, string>
     */
    private function extractPaginationUrlsFromHtml(string $html, string $baseUrl): array
    {
        $pattern = '#(?:https?://[^\s"\'<>]+|/[^\s"\'<>]+?)/page/\d+/?#i';

        if (! preg_match_all($pattern, $html, $matches)) {
            return [];
        }

        $basePath = parse_url($baseUrl, PHP_URL_PATH);
        $normalizedBasePath = $this->normalizePaginationBasePath(is_string($basePath) ? $basePath : null);
        $candidates = [];

        foreach ($matches[0] as $match) {
            if (! is_string($match) || trim($match) === '') {
                continue;
            }

            $resolved = $this->resolveUrl($match, $baseUrl);

            if (! is_string($resolved) || trim($resolved) === '') {
                continue;
            }

            if (! $this->isSameHost($resolved, $baseUrl)) {
                continue;
            }

            $resolvedPath = parse_url($resolved, PHP_URL_PATH);
            if (! is_string($resolvedPath)) {
                continue;
            }

            if (
                $normalizedBasePath !== ''
                && ! str_contains(rtrim($resolvedPath, '/'), $normalizedBasePath.'/page/')
            ) {
                continue;
            }

            $candidates[] = $resolved;
        }

        $candidates = array_values(array_unique($candidates));

        usort($candidates, function (string $left, string $right): int {
            $leftPage = $this->extractPageNumberFromUrl($left);
            $rightPage = $this->extractPageNumberFromUrl($right);

            if ($leftPage === $rightPage) {
                return strcmp($left, $right);
            }

            return $leftPage <=> $rightPage;
        });

        return $candidates;
    }

    private function extractPageNumberFromUrl(string $url): int
    {
        if (! preg_match('#/page/(\d+)#i', $url, $matches)) {
            return PHP_INT_MAX;
        }

        return (int) ($matches[1] ?? PHP_INT_MAX);
    }

    private function normalizePaginationBasePath(?string $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return '';
        }

        $normalized = rtrim($path, '/');
        $normalized = preg_replace('#/page/\d+$#i', '', $normalized) ?? $normalized;

        return rtrim($normalized, '/');
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (Str::startsWith($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$url;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $base = parse_url($baseUrl);

        if (! $base || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        $scheme = $base['scheme'];
        $host = $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $path = $base['path'] ?? '/';

        if (Str::startsWith($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        $directory = Str::finish($directory, '/');
        $directory = Str::start($directory, '/');

        return "{$scheme}://{$host}{$port}{$directory}{$url}";
    }

    private function extractCanonicalUrl(Crawler $crawler, string $fallback): string
    {
        $canonical = $this->firstAttr($crawler, 'link[rel="canonical"]', 'href')
            ?? $this->firstAttr($crawler, 'meta[property="og:url"]', 'content');

        if ($canonical) {
            $resolved = $this->resolveUrl($canonical, $fallback);

            if ($resolved) {
                return $resolved;
            }
        }

        return $fallback;
    }

    private function extractTitle(Crawler $crawler): string
    {
        $candidates = [
            $this->firstAttr($crawler, 'meta[property="og:title"]', 'content'),
            $this->firstAttr($crawler, 'meta[name="twitter:title"]', 'content'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate) {
                return $candidate;
            }
        }

        $h1 = $crawler->filter('h1');

        if ($h1->count() > 0) {
            $text = $this->normalizeWhitespace($h1->first()->text(''));

            if ($text !== '') {
                return $text;
            }
        }

        $title = $crawler->filter('title');

        if ($title->count() > 0) {
            return $this->normalizeWhitespace($title->first()->text(''));
        }

        return '';
    }

    private function extractPublishedAt(Crawler $crawler): ?Carbon
    {
        $metaSelectors = [
            'meta[property="article:published_time"]',
            'meta[name="article:published_time"]',
            'meta[name="pubdate"]',
            'meta[name="publish-date"]',
            'meta[name="date"]',
            'meta[itemprop="datePublished"]',
        ];

        foreach ($metaSelectors as $selector) {
            $value = $this->firstAttr($crawler, $selector, 'content');

            if ($value) {
                $date = $this->parseDate($value);

                if ($date) {
                    return $date;
                }
            }
        }

        $timeTag = $crawler->filter('time');

        if ($timeTag->count() > 0) {
            $attr = $timeTag->first()->attr('datetime') ?? '';
            $text = $timeTag->first()->text('');
            $candidate = $attr !== '' ? $attr : $text;

            $date = $this->parseDate($candidate);

            if ($date) {
                return $date;
            }
        }

        return null;
    }

    private function extractMetaDescription(Crawler $crawler): string
    {
        $candidates = [
            $this->firstAttr($crawler, 'meta[name="description"]', 'content'),
            $this->firstAttr($crawler, 'meta[property="og:description"]', 'content'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param  array<int, string>  $removeSelectors
     * @return array{0: ?string, 1: string}
     */
    private function extractBody(Crawler $crawler, string $contentSelector, array $removeSelectors): array
    {
        $nodes = $crawler->filter($contentSelector);

        if ($nodes->count() === 0) {
            return [null, ''];
        }

        $htmlParts = [];

        foreach ($nodes as $node) {
            $nodeCrawler = new Crawler($node);

            foreach ($removeSelectors as $removeSelector) {
                $nodeCrawler->filter($removeSelector)->each(function (Crawler $removeNode) {
                    $domNode = $removeNode->getNode(0);

                    if ($domNode && $domNode->parentNode) {
                        $domNode->parentNode->removeChild($domNode);
                    }
                });
            }

            $html = $nodeCrawler->html();

            if ($html !== null) {
                $htmlParts[] = trim($html);
            }
        }

        if (empty($htmlParts)) {
            return [null, ''];
        }

        $rawHtml = implode("\n", $htmlParts);
        $cleaned = $this->extractCleanedText($rawHtml);

        return [$rawHtml, $cleaned];
    }

    private function determineContentType(string $cleanedText, bool $bestEffort): string
    {
        $wordCount = str_word_count($cleanedText);
        $charCount = strlen($cleanedText);

        if ($wordCount >= 80 || $charCount >= 600) {
            return 'full';
        }

        if ($bestEffort && ($wordCount >= 30 || $charCount >= 250)) {
            return 'full';
        }

        return 'snippet';
    }

    private function extractCleanedText(string $html): string
    {
        $crawler = new Crawler($html);

        $root = $crawler->filter('#contents');

        if ($root->count() === 0) {
            $root = $crawler->filter('main, article');
        }

        if ($root->count() === 0) {
            $root = $crawler->filter('body');
        }

        $parts = [];

        foreach (['h1', 'h2', 'h3', 'p', 'li'] as $selector) {
            $root->filter($selector)->each(function (Crawler $node) use (&$parts) {
                $text = $this->normalizeWhitespace($node->text(''));

                if ($text !== '') {
                    $parts[] = $text;
                }
            });
        }

        $root->filter('table')->each(function (Crawler $table) use (&$parts) {
            $rows = $table->filter('tr')->each(function (Crawler $tr) {
                $cells = $tr->filter('th,td')->each(function (Crawler $cell) {
                    return $this->normalizeWhitespace($cell->text(''));
                });

                $cells = array_values(array_filter($cells, fn (string $c) => $c !== ''));

                return implode(' | ', $cells);
            });

            $rows = array_values(array_filter($rows, fn (string $r) => $r !== ''));

            if (! empty($rows)) {
                $parts[] = implode("\n", $rows);
            }
        });

        $parts = array_values(array_filter($parts, fn (string $t) => $t !== ''));

        $text = trim(implode("\n\n", $parts));

        if ($text === '') {
            $text = $this->fallbackHtmlToText($html);
        }

        return $text;
    }

    private function fallbackHtmlToText(string $html): string
    {
        $replacements = [
            '</p>' => "\n\n",
            '</li>' => "\n",
            '<br>' => "\n",
            '<br/>' => "\n",
            '<br />' => "\n",
            '</h1>' => "\n\n",
            '</h2>' => "\n\n",
            '</h3>' => "\n\n",
            '</tr>' => "\n",
        ];

        $value = str_ireplace(array_keys($replacements), array_values($replacements), $html);
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $value = preg_replace("/\r\n?/", "\n", $value) ?? '';
        $value = preg_replace("/[ \t\f\v]+/", ' ', $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return trim($value);
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attr): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $value = $node->first()->attr($attr);

        return $value ? $this->normalizeWhitespace($value) : null;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function isSameHost(string $url, string $baseUrl): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($urlHost) || ! is_string($baseHost)) {
            return false;
        }

        return mb_strtolower($urlHost) === mb_strtolower($baseHost);
    }

    private function isLikelyProfileUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        $normalizedPath = '/'.trim(mb_strtolower($path), '/').'/';

        foreach (['staff_profile', 'staff_name', 'author', 'staff', 'category', 'tag'] as $segment) {
            if (str_contains($normalizedPath, '/'.$segment.'/')) {
                return true;
            }
        }

        return false;
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            $date = Carbon::parse($value);

            if (! $this->valueContainsExplicitTime($value)) {
                return $date->startOfDay();
            }

            return $date;
        } catch (\Throwable) {
            return null;
        }
    }

    private function valueContainsExplicitTime(string $value): bool
    {
        return preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', $value) === 1;
    }

    private function resolveRenderer(mixed $value): string
    {
        if (! is_string($value)) {
            return 'http';
        }

        $normalized = mb_strtolower(trim($value));

        if (! in_array($normalized, ['auto', 'http', 'playwright'], true)) {
            return 'http';
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $playwrightOptions
     */
    private function fetchPageHtml(string $url, string $renderer, bool $required, array $playwrightOptions, bool $throwOnBlocked = false): ?string
    {
        $result = $playwrightOptions === []
            ? $this->pageFetcher->fetch($url, $renderer)
            : $this->pageFetcher->fetch($url, $renderer, $playwrightOptions);
        $html = $result['body'] ?? null;
        $playwrightHtml = null;

        if (is_string($html) && ! $this->looksLikeBotChallengePage($html)) {
            return $html;
        }

        if ($renderer !== 'playwright') {
            $playwrightResult = $playwrightOptions === []
                ? $this->pageFetcher->fetch($url, 'playwright')
                : $this->pageFetcher->fetch($url, 'playwright', $playwrightOptions);
            $playwrightHtml = $playwrightResult['body'] ?? null;

            if (is_string($playwrightHtml) && ! $this->looksLikeBotChallengePage($playwrightHtml)) {
                return $playwrightHtml;
            }
        }

        $blockedByAntiBot = (
            (is_string($html) && $this->looksLikeBotChallengePage($html))
            || (is_string($playwrightHtml) && $this->looksLikeBotChallengePage($playwrightHtml))
        );

        if ($blockedByAntiBot && ($required || $throwOnBlocked)) {
            $message = $required
                ? 'Listing page is blocked by anti-bot protection. Consider using Playwright renderer with a persistent session or a source feed URL.'
                : 'Article pages are blocked by anti-bot protection. Consider using Playwright renderer with a persistent session or a source feed URL.';

            throw new InvalidArgumentException($message);
        }

        if ($required) {
            throw new InvalidArgumentException('Failed to fetch listing page');
        }

        return null;
    }

    private function looksLikeBotChallengePage(string $html): bool
    {
        $lower = mb_strtolower($html);
        $markers = [
            'px-captcha',
            'access to this page has been denied',
            'before we continue',
            'cf-chl-',
            'checking your browser',
            'javascript required',
            'verify you are human',
        ];

        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isAntiBotException(InvalidArgumentException $exception): bool
    {
        return str_contains(mb_strtolower($exception->getMessage()), 'anti-bot protection');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolvePlaywrightOptions(array $config): array
    {
        $playwrightConfig = Arr::get($config, 'fetch.playwright');

        if (! is_array($playwrightConfig)) {
            return [];
        }

        $options = [];

        $timeoutValue = $playwrightConfig['timeout_ms'] ?? null;
        if (is_numeric($timeoutValue)) {
            $options['timeout_ms'] = (int) $timeoutValue;
        }

        $waitSelectorValue = $playwrightConfig['wait_selector'] ?? null;
        if (is_string($waitSelectorValue) && trim($waitSelectorValue) !== '') {
            $options['wait_selector'] = trim($waitSelectorValue);
        }

        $userAgentValue = $playwrightConfig['user_agent'] ?? null;
        if (is_string($userAgentValue) && trim($userAgentValue) !== '') {
            $options['user_agent'] = trim($userAgentValue);
        }

        $storagePathValue = $playwrightConfig['storage_state_path'] ?? null;
        if (is_string($storagePathValue) && trim($storagePathValue) !== '') {
            $options['storage_state_path'] = trim($storagePathValue);
        }

        $refreshOnBlockedValue = $playwrightConfig['refresh_on_blocked'] ?? null;
        if (is_bool($refreshOnBlockedValue)) {
            $options['refresh_on_blocked'] = $refreshOnBlockedValue;
        }

        $refreshAttemptsValue = $playwrightConfig['refresh_attempts'] ?? null;
        if (is_numeric($refreshAttemptsValue)) {
            $options['refresh_attempts'] = (int) $refreshAttemptsValue;
        }

        $autoScrollValue = $playwrightConfig['auto_scroll'] ?? null;
        if (is_bool($autoScrollValue)) {
            $options['auto_scroll'] = $autoScrollValue;
        }

        $maxScrollStepsValue = $playwrightConfig['max_scroll_steps'] ?? null;
        if (is_numeric($maxScrollStepsValue)) {
            $options['max_scroll_steps'] = (int) $maxScrollStepsValue;
        }

        $scrollPauseMsValue = $playwrightConfig['scroll_pause_ms'] ?? null;
        if (is_numeric($scrollPauseMsValue)) {
            $options['scroll_pause_ms'] = (int) $scrollPauseMsValue;
        }

        $proxyValue = $playwrightConfig['proxy'] ?? null;

        if (is_array($proxyValue)) {
            $proxy = [];

            $serverValue = $proxyValue['server'] ?? null;
            if (is_string($serverValue) && trim($serverValue) !== '') {
                $proxy['server'] = trim($serverValue);
            }

            $usernameValue = $proxyValue['username'] ?? null;
            if (is_string($usernameValue) && trim($usernameValue) !== '') {
                $proxy['username'] = trim($usernameValue);
            }

            $passwordValue = $proxyValue['password'] ?? null;
            if (is_string($passwordValue) && trim($passwordValue) !== '') {
                $proxy['password'] = trim($passwordValue);
            }

            $bypassValue = $proxyValue['bypass'] ?? null;
            if (is_string($bypassValue) && trim($bypassValue) !== '') {
                $proxy['bypass'] = trim($bypassValue);
            }

            if (array_key_exists('server', $proxy)) {
                $options['proxy'] = $proxy;
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $listingPlaywrightOptions
     * @return array<string, mixed>
     */
    private function resolveArticlePlaywrightOptions(array $listingPlaywrightOptions): array
    {
        unset($listingPlaywrightOptions['auto_scroll'], $listingPlaywrightOptions['max_scroll_steps'], $listingPlaywrightOptions['scroll_pause_ms']);

        return $listingPlaywrightOptions;
    }
}
