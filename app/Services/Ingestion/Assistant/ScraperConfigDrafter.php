<?php

namespace App\Services\Ingestion\Assistant;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ScraperConfigDrafter
{
    public function __construct(
        private readonly ScraperConfigHeuristicGenerator $heuristicGenerator,
        private readonly ScraperConfigAiRefiner $aiRefiner,
    ) {}

    /**
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float, mode: string}
     */
    public function draft(string $type, string $sourceUrl, string $html): array
    {
        $heuristic = $this->heuristicGenerator->generate($type, $sourceUrl, $html);
        $result = $heuristic;
        $mode = 'heuristic';

        $normalizedType = mb_strtolower(trim($type));
        $refineEnabled = (bool) config('scraper-assistant.ai.refine_enabled', true);
        $refineHtmlAlways = (bool) config('scraper-assistant.ai.refine_html_always', true);
        $minimumConfidence = (float) config('scraper-assistant.ai.refine_min_confidence', 0.75);

        $shouldRefine = $refineEnabled
            && (
                ($normalizedType === 'html' && $refineHtmlAlways)
                || $heuristic['confidence'] < $minimumConfidence
            );

        if ($shouldRefine) {
            $result = $this->aiRefiner->refine($type, $sourceUrl, $html, $heuristic);
            $result = $this->preserveHeuristicSpecificity($sourceUrl, $html, $heuristic, $result);
            $mode = 'ai_refined';
        }

        $result = $this->applyHtmlFetchHardeningDefaults($type, $sourceUrl, $result);

        Log::info('Scraper assistant generated config draft.', [
            'source_url' => $sourceUrl,
            'type' => $type,
            'mode' => $mode,
            'profile' => $result['profile'],
            'confidence' => $result['confidence'],
            'warning_count' => count($result['warnings']),
        ]);

        return [
            ...$result,
            'mode' => $mode,
        ];
    }

    /**
     * @param  array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}  $heuristic
     * @param  array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}  $result
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    private function preserveHeuristicSpecificity(string $sourceUrl, string $html, array $heuristic, array $result): array
    {
        if (($heuristic['profile'] ?? null) !== 'generic_listing' || ($result['profile'] ?? null) !== 'generic_listing') {
            return $result;
        }

        $heuristicLinkSelector = Arr::get($heuristic, 'config.list.link_selector');
        $resultLinkSelector = Arr::get($result, 'config.list.link_selector');

        if (
            is_string($heuristicLinkSelector)
            && is_string($resultLinkSelector)
            && $this->isBroadLinkSelector($resultLinkSelector)
            && ! $this->isBroadLinkSelector($heuristicLinkSelector)
        ) {
            Arr::set($result, 'config.list.link_selector', $heuristicLinkSelector);
        }

        $heuristicContentSelector = Arr::get($heuristic, 'config.article.content_selector');
        $resultContentSelector = Arr::get($result, 'config.article.content_selector');

        if (
            is_string($heuristicContentSelector)
            && is_string($resultContentSelector)
            && $this->isGenericContentSelector($resultContentSelector)
            && ! $this->isGenericContentSelector($heuristicContentSelector)
        ) {
            Arr::set($result, 'config.article.content_selector', $heuristicContentSelector);
        }

        $heuristicPaginationSelector = Arr::get($heuristic, 'config.list.pagination_selector');
        $resultPaginationSelector = Arr::get($result, 'config.list.pagination_selector');

        if (
            is_string($heuristicPaginationSelector)
            && trim($heuristicPaginationSelector) !== ''
            && (! is_string($resultPaginationSelector) || trim($resultPaginationSelector) === '')
        ) {
            Arr::set($result, 'config.list.pagination_selector', $heuristicPaginationSelector);
        }

        $heuristicPaginationAttr = Arr::get($heuristic, 'config.list.pagination_attr');
        $resultPaginationAttr = Arr::get($result, 'config.list.pagination_attr');

        if (
            is_string($heuristicPaginationAttr)
            && trim($heuristicPaginationAttr) !== ''
            && (! is_string($resultPaginationAttr) || trim($resultPaginationAttr) === '')
        ) {
            Arr::set($result, 'config.list.pagination_attr', $heuristicPaginationAttr);
        }

        $heuristicMaxPages = Arr::get($heuristic, 'config.list.max_pages');
        $resultMaxPages = Arr::get($result, 'config.list.max_pages');

        if (
            is_numeric($heuristicMaxPages)
            && (is_numeric($resultMaxPages) ? (int) $resultMaxPages < (int) $heuristicMaxPages : true)
        ) {
            Arr::set($result, 'config.list.max_pages', (int) $heuristicMaxPages);
        }

        return $this->ensureRefinedLinkSelectorHasMatches(
            sourceUrl: $sourceUrl,
            html: $html,
            heuristic: $heuristic,
            result: $result,
        );
    }

    /**
     * @param  array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}  $heuristic
     * @param  array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}  $result
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    private function ensureRefinedLinkSelectorHasMatches(string $sourceUrl, string $html, array $heuristic, array $result): array
    {
        if (($result['profile'] ?? null) !== 'generic_listing') {
            return $result;
        }

        $resultSelector = Arr::get($result, 'config.list.link_selector');

        if (! is_string($resultSelector) || trim($resultSelector) === '') {
            return $result;
        }

        if ($this->selectorMatchCount($html, $sourceUrl, $resultSelector) > 0) {
            return $result;
        }

        $heuristicSelector = Arr::get($heuristic, 'config.list.link_selector');

        if (! is_string($heuristicSelector) || trim($heuristicSelector) === '') {
            return $result;
        }

        if ($this->selectorMatchCount($html, $sourceUrl, $heuristicSelector) <= 0) {
            return $result;
        }

        Arr::set($result, 'config.list.link_selector', $heuristicSelector);

        $warnings = collect(Arr::wrap($result['warnings'] ?? []))
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (mixed $warning): string => trim((string) $warning))
            ->push('AI link selector matched no listing links. Reverted to heuristic selector.')
            ->unique()
            ->values()
            ->all();

        $result['warnings'] = $warnings;

        return $result;
    }

    private function selectorMatchCount(string $html, string $baseUrl, string $selector): int
    {
        $trimmedSelector = trim($selector);

        if ($trimmedSelector === '') {
            return 0;
        }

        try {
            $crawler = new Crawler($html, $baseUrl);

            return $crawler->filter($trimmedSelector)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function isBroadLinkSelector(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        $broadSelectors = [
            'a[href]',
            'main a',
            'article a',
            'h2 a',
            'h3 a',
            'h4 a',
        ];

        return in_array($normalized, $broadSelectors, true);
    }

    private function isGenericContentSelector(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        $genericSelectors = [
            'article',
            'main',
            '.content',
            '#content',
            '.entry-content',
            '.article-content',
        ];

        return in_array($normalized, $genericSelectors, true);
    }

    /**
     * @param  array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}  $result
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    private function applyHtmlFetchHardeningDefaults(string $type, string $sourceUrl, array $result): array
    {
        if (! (bool) config('scraper-assistant.html_defaults.apply_fetch_hardening', true)) {
            return $result;
        }

        if (mb_strtolower(trim($type)) !== 'html') {
            return $result;
        }

        if (! in_array($result['profile'] ?? null, ['generic_listing', 'wichitadocumenters'], true)) {
            return $result;
        }

        $config = $result['config'] ?? [];

        if (! is_array($config)) {
            return $result;
        }

        $fetch = Arr::get($config, 'fetch', []);
        $fetch = is_array($fetch) ? $fetch : [];

        $renderer = $fetch['renderer'] ?? null;
        if (! is_string($renderer) || trim($renderer) === '') {
            $fetch['renderer'] = (string) config('scraper-assistant.html_defaults.fetch_renderer', 'auto');
        }

        $playwright = Arr::get($fetch, 'playwright', []);
        $playwright = is_array($playwright) ? $playwright : [];

        if (! is_numeric($playwright['timeout_ms'] ?? null)) {
            $playwright['timeout_ms'] = (int) config('scraper-assistant.html_defaults.playwright.timeout_ms', 45000);
        }

        $waitSelector = $playwright['wait_selector'] ?? null;
        if (! is_string($waitSelector) || trim($waitSelector) === '') {
            $defaultWaitSelector = trim((string) config('scraper-assistant.html_defaults.playwright.wait_selector', 'main'));

            if ($defaultWaitSelector !== '') {
                $playwright['wait_selector'] = $defaultWaitSelector;
            }
        }

        if (! is_bool($playwright['refresh_on_blocked'] ?? null)) {
            $playwright['refresh_on_blocked'] = (bool) config('scraper-assistant.html_defaults.playwright.refresh_on_blocked', true);
        }

        if (! is_numeric($playwright['refresh_attempts'] ?? null)) {
            $playwright['refresh_attempts'] = (int) config('scraper-assistant.html_defaults.playwright.refresh_attempts', 2);
        }

        if (($result['profile'] ?? null) === 'generic_listing') {
            if (! is_bool($playwright['auto_scroll'] ?? null)) {
                $playwright['auto_scroll'] = (bool) config('scraper-assistant.html_defaults.playwright.auto_scroll', true);
            }

            $defaultMaxScrollSteps = (int) config('scraper-assistant.html_defaults.playwright.max_scroll_steps', 12);
            $configuredMaxScrollSteps = is_numeric($playwright['max_scroll_steps'] ?? null)
                ? (int) $playwright['max_scroll_steps']
                : $defaultMaxScrollSteps;
            $playwright['max_scroll_steps'] = max($defaultMaxScrollSteps, $configuredMaxScrollSteps);

            $defaultScrollPauseMs = (int) config('scraper-assistant.html_defaults.playwright.scroll_pause_ms', 500);
            $configuredScrollPauseMs = is_numeric($playwright['scroll_pause_ms'] ?? null)
                ? (int) $playwright['scroll_pause_ms']
                : $defaultScrollPauseMs;
            $playwright['scroll_pause_ms'] = max($defaultScrollPauseMs, $configuredScrollPauseMs);
        }

        $storageStatePath = $playwright['storage_state_path'] ?? null;
        if (! is_string($storageStatePath) || trim($storageStatePath) === '') {
            $resolvedStorageStatePath = $this->resolveStorageStatePath($sourceUrl);

            if ($resolvedStorageStatePath !== null) {
                $playwright['storage_state_path'] = $resolvedStorageStatePath;
            }
        }

        $fetch['playwright'] = $playwright;
        $config['fetch'] = $fetch;
        $result['config'] = $config;

        return $result;
    }

    private function resolveStorageStatePath(string $sourceUrl): ?string
    {
        $host = parse_url($sourceUrl, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $normalizedHost = mb_strtolower(trim($host));
        if (Str::startsWith($normalizedHost, 'www.')) {
            $normalizedHost = (string) Str::after($normalizedHost, 'www.');
        }

        $sanitizedHost = preg_replace('/[^a-z0-9.-]+/', '-', $normalizedHost) ?? '';
        $sanitizedHost = trim($sanitizedHost, '-.');

        if ($sanitizedHost === '') {
            return null;
        }

        $storageDir = (string) config('scraper-assistant.html_defaults.playwright.storage_state_dir', 'storage/app/playwright');
        $storageDir = str_replace('\\', '/', trim($storageDir));
        $storageDir = rtrim($storageDir, '/');

        if ($storageDir === '') {
            return null;
        }

        return $storageDir.'/'.$sanitizedHost.'.json';
    }
}
