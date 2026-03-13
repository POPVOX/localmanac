<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\Scraper;
use App\Services\Chat\Ingestion\PageFetcher;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class DocumentersFetcher
{
    private const DOCUMENTERS_DATE_PATTERN = '/Date:\s*((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})/i';

    public function __construct(
        private readonly PageFetcher $pageFetcher,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(Scraper $scraper): array
    {
        $sourceUrl = $scraper->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('Scraper source_url must exist');
        }

        $listConfig = $scraper->config['list'] ?? null;

        if (! is_array($listConfig)) {
            throw new InvalidArgumentException('Scraper list config must exist');
        }

        $linkSelector = $listConfig['link_selector'] ?? null;

        if (! $linkSelector) {
            throw new InvalidArgumentException('Scraper list link_selector must exist');
        }

        $linkAttr = $listConfig['link_attr'] ?? 'href';
        $maxLinks = (int) ($listConfig['max_links'] ?? 50);
        $renderer = $this->resolveRenderer($scraper->config['fetch']['renderer'] ?? null);
        $listingPlaywrightOptions = $this->resolvePlaywrightOptions(is_array($scraper->config) ? $scraper->config : []);
        $detailPlaywrightOptions = $this->resolveDetailPlaywrightOptions($listingPlaywrightOptions);

        $listingHtml = $this->fetchPageHtml($sourceUrl, $renderer, true, $listingPlaywrightOptions);
        $links = $this->extractDocLinks($listingHtml, $linkSelector, $linkAttr, $maxLinks);
        $items = [];
        $blockedDetailCount = 0;

        foreach ($links as $url) {
            try {
                $rawHtml = $this->fetchPageHtml($url, $renderer, false, $detailPlaywrightOptions, true);
            } catch (InvalidArgumentException $exception) {
                if ($this->isAntiBotException($exception)) {
                    $blockedDetailCount++;

                    continue;
                }

                throw $exception;
            }

            if ($rawHtml === null) {
                continue;
            }

            $cleanedText = $this->extractCleanedText($rawHtml);

            if ($cleanedText === '') {
                continue;
            }

            $items[] = [
                'city_id' => $scraper->city_id,
                'scraper_id' => $scraper->id,
                'title' => $scraper->name.' — Notes',
                'published_at' => $this->extractPublishedAt($rawHtml),
                'summary' => null,
                'body' => [
                    'raw_html' => $rawHtml,
                    'cleaned_text' => $cleanedText,
                ],
                'source' => [
                    'source_type' => 'html',
                    'source_url' => $url,
                ],
                'content_hash' => sha1($cleanedText),
            ];
        }

        if ($items === [] && $blockedDetailCount > 0) {
            throw new InvalidArgumentException('Detail pages are blocked by anti-bot protection. Consider using Playwright renderer with a persistent session or a source feed URL.');
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function extractDocLinks(string $html, string $linkSelector, string $linkAttr, int $maxLinks): array
    {
        $crawler = new Crawler($html, 'https://wichitadocumenters.org');

        $links = $crawler->filter($linkSelector)->each(function (Crawler $node) use ($linkAttr) {
            $href = $node->attr($linkAttr) ?? '';
            $href = trim($href);

            if ($href === '') {
                return null;
            }

            return $this->normalizeUrl($href);
        });

        $links = array_values(array_unique(array_filter($links, function (?string $url) {
            return is_string($url) && Str::contains($url, 'docs.google.com');
        })));

        if ($maxLinks > 0) {
            $links = array_slice($links, 0, $maxLinks);
        }

        return $links;
    }

    private function extractCleanedText(string $html): string
    {
        $crawler = new Crawler($html);

        // Prefer the main document container when parsing published Google Docs.
        // Google Docs "Publish to the web" pages usually render content inside #contents.
        $root = $crawler->filter('#contents');

        // Fallbacks for non-Google-doc HTML variants.
        if ($root->count() === 0) {
            $root = $crawler->filter('main, article');
        }

        if ($root->count() === 0) {
            $root = $crawler->filter('body');
        }

        $parts = [];

        // Extract from common block elements.
        foreach (['h1', 'h2', 'h3', 'p', 'li'] as $selector) {
            $root->filter($selector)->each(function (Crawler $node) use (&$parts) {
                $text = $this->normalizeWhitespace($node->text(''));

                if ($text !== '') {
                    $parts[] = $text;
                }
            });
        }

        // Extract table text (Google Docs often uses tables for agendas/roll calls).
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

        // If DomCrawler-based extraction yields nothing (common when pages are heavy on scripts),
        // fall back to a lightweight HTML->text conversion.
        if ($text === '') {
            $text = $this->fallbackHtmlToText($html);
        }

        return $text;
    }

    private function fallbackHtmlToText(string $html): string
    {
        // Preserve some structure before stripping tags.
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

        // Normalize whitespace while keeping newlines.
        $value = preg_replace("/\r\n?/", "\n", $value) ?? '';
        $value = preg_replace("/[ \t\f\v]+/", ' ', $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return trim($value);
    }

    private function extractPublishedAt(string $html): ?Carbon
    {
        $candidate = $this->matchPublishedAtCandidate($html);

        if ($candidate === null) {
            $candidate = $this->matchPublishedAtCandidate($this->fallbackHtmlToText($html));
        }

        if ($candidate === null) {
            $candidate = $this->matchTitleBannerDateCandidate($html);
        }

        if ($candidate === null) {
            return null;
        }

        try {
            return Carbon::parse($this->normalizePublishedAtCandidate($candidate));
        } catch (\Throwable) {
            return null;
        }
    }

    private function matchPublishedAtCandidate(string $content): ?string
    {
        if (preg_match(self::DOCUMENTERS_DATE_PATTERN, $content, $matches) !== 1) {
            return null;
        }

        $candidate = trim((string) ($matches[1] ?? ''));

        return $candidate === '' ? null : $candidate;
    }

    private function normalizePublishedAtCandidate(string $candidate): string
    {
        $normalized = str_replace('.', '', trim($candidate));

        return str_ireplace(
            ['Jan ', 'Feb ', 'Mar ', 'Apr ', 'Jun ', 'Jul ', 'Aug ', 'Sep ', 'Sept ', 'Oct ', 'Nov ', 'Dec '],
            ['January ', 'February ', 'March ', 'April ', 'June ', 'July ', 'August ', 'September ', 'September ', 'October ', 'November ', 'December '],
            $normalized,
        );
    }

    private function matchTitleBannerDateCandidate(string $html): ?string
    {
        $patterns = [
            '/<title>[^<]*?Meeting\s+(\d{1,2}\/\d{1,2}\/\d{4})<\/title>/i',
            '/id="title"[^>]*>[^<]*?Meeting\s+(\d{1,2}\/\d{1,2}\/\d{4})</i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $candidate = trim((string) ($matches[1] ?? ''));

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\\s+/', ' ', $value) ?? '');
    }

    private function normalizeUrl(string $url): string
    {
        if (Str::startsWith($url, '//')) {
            return 'https:'.$url;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            return 'https://wichitadocumenters.org/'.ltrim($url, '/');
        }

        return $url;
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
                : 'Detail pages are blocked by anti-bot protection. Consider using Playwright renderer with a persistent session or a source feed URL.';

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
    private function resolveDetailPlaywrightOptions(array $listingPlaywrightOptions): array
    {
        unset($listingPlaywrightOptions['auto_scroll'], $listingPlaywrightOptions['max_scroll_steps'], $listingPlaywrightOptions['scroll_pause_ms']);

        return $listingPlaywrightOptions;
    }
}
