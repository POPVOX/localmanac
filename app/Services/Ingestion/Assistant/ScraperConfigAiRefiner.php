<?php

namespace App\Services\Ingestion\Assistant;

use App\Services\Ingestion\Assistant\Agents\ScraperConfigRefinerAgent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Providers\Tools\WebFetch;

class ScraperConfigAiRefiner
{
    /**
     * @param  array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}  $heuristic
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    public function refine(string $type, string $sourceUrl, string $html, array $heuristic): array
    {
        $agent = new ScraperConfigRefinerAgent($this->resolveTools($sourceUrl));

        try {
            $response = $agent->prompt(
                $this->buildPrompt($type, $sourceUrl, $html, $heuristic),
                provider: $this->resolveProviderPreference(),
                timeout: (int) config('scraper-assistant.ai.timeout', 45),
            );
        } catch (\Throwable $exception) {
            Log::warning('Scraper assistant AI refinement failed.', [
                'source_url' => $sourceUrl,
                'type' => $type,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $heuristic['warnings'][] = 'AI refinement was unavailable. Using heuristic draft.';

            return $heuristic;
        }

        $structured = is_array($response->structured ?? null) ? $response->structured : [];
        $profile = (string) ($structured['profile'] ?? '');
        $config = is_array($structured['config'] ?? null) ? $structured['config'] : [];

        if (! in_array($profile, ['rss', 'documenters', 'generic_listing', 'civicplus_archive_pdf_list', 'wichitadocumenters', 'wichita_archive_pdf_list'], true)) {
            $heuristic['warnings'][] = 'AI refinement returned an unsupported profile. Using heuristic draft.';

            return $heuristic;
        }

        $config = $this->stripNullValues($config);
        $normalized = $this->normalizeRefinedConfig($type, $profile, $heuristic['config'], $config);

        $warnings = collect($heuristic['warnings'])
            ->merge(Arr::wrap($structured['warnings'] ?? []))
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (mixed $warning): string => trim((string) $warning))
            ->unique()
            ->values()
            ->all();

        $confidence = $this->normalizeConfidence($structured['confidence'] ?? null, $heuristic['confidence']);

        return [
            'profile' => $profile,
            'config' => $normalized,
            'warnings' => $warnings,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}  $heuristic
     */
    private function buildPrompt(string $type, string $sourceUrl, string $html, array $heuristic): string
    {
        $htmlSnippet = mb_substr($html, 0, 45000);

        return implode("\n", [
            'Generate a valid scraper config for the supported profiles only.',
            'Supported profiles: rss, documenters, generic_listing, civicplus_archive_pdf_list.',
            'Do not invent new keys outside schema.',
            'Never output placeholder values like `proxy.example.com`, `path/to/storage/state`, `user`, or `pass`.',
            'For html profiles, prefer robust configs with specific selectors and fetch.playwright settings.',
            'For infinite-scroll or lazy-loaded listings, set fetch.playwright.auto_scroll to true.',
            'Avoid broad selectors like `article a` when more specific title-link selectors exist.',
            'Prefer retaining the heuristic draft unless clear evidence suggests improvement.',
            '',
            'Scraper type:',
            $type,
            '',
            'Source URL:',
            $sourceUrl,
            '',
            'Heuristic draft:',
            json_encode($heuristic, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
            '',
            'Source HTML snippet:',
            $htmlSnippet,
        ]);
    }

    /**
     * @return array<int, \Laravel\Ai\Providers\Tools\WebFetch>
     */
    private function resolveTools(string $sourceUrl): array
    {
        if (! (bool) config('scraper-assistant.ai.webfetch_enabled', false)) {
            return [];
        }

        $domain = $this->normalizeDomain($sourceUrl);

        if ($domain === '') {
            return [];
        }

        return [
            (new WebFetch)->max(1)->allow([$domain]),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function resolveProviderPreference(): array
    {
        if ((bool) config('scraper-assistant.ai.webfetch_enabled', false)) {
            $webFetchProvider = $this->resolveWebFetchProvider();

            if ($webFetchProvider !== null) {
                return [
                    $webFetchProvider => (string) config('scraper-assistant.ai.webfetch_model', 'claude-3-5-haiku-latest'),
                ];
            }
        }

        $providers = config('scraper-assistant.ai.provider_chain', ['openai']);

        if (! is_array($providers) || $providers === []) {
            return [
                'openai' => (string) config('scraper-assistant.ai.model', 'gpt-4o-mini'),
            ];
        }

        $resolved = [];

        foreach (array_values($providers) as $index => $provider) {
            if (! is_string($provider) || trim($provider) === '') {
                continue;
            }

            $resolved[$provider] = $index === 0
                ? (string) config('scraper-assistant.ai.model', 'gpt-4o-mini')
                : null;
        }

        if ($resolved === []) {
            return [
                'openai' => (string) config('scraper-assistant.ai.model', 'gpt-4o-mini'),
            ];
        }

        return $resolved;
    }

    private function resolveWebFetchProvider(): ?string
    {
        $providers = config('scraper-assistant.ai.webfetch_provider_chain', ['anthropic', 'gemini']);

        if (! is_array($providers)) {
            return null;
        }

        foreach ($providers as $provider) {
            if (! is_string($provider) || trim($provider) === '') {
                continue;
            }

            $key = (string) config('ai.providers.'.$provider.'.key', '');

            if ($key !== '') {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function stripNullValues(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }

            if (is_array($item)) {
                $item = $this->stripNullValues($item);
            }

            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $heuristicConfig
     * @param  array<string, mixed>  $refinedConfig
     * @return array<string, mixed>
     */
    private function normalizeRefinedConfig(string $type, string $profile, array $heuristicConfig, array $refinedConfig): array
    {
        $config = $this->mergeRecursive($heuristicConfig, $refinedConfig);

        if ($type === 'rss' || $profile === 'rss') {
            unset($config['profile'], $config['list'], $config['article'], $config['fetch'], $config['pdf']);

            return $config;
        }

        unset($config['feed_url'], $config['lang'], $config['max_items']);
        $config['profile'] = $profile;

        if (in_array($profile, ['generic_listing', 'documenters', 'wichitadocumenters'], true)) {
            unset($config['pdf']);
        }

        $proxy = Arr::get($config, 'fetch.playwright.proxy');
        if (! is_array($proxy) || $proxy === [] || ! is_string($proxy['server'] ?? null) || trim((string) $proxy['server']) === '') {
            Arr::forget($config, 'fetch.playwright.proxy');
        } elseif ($this->isPlaceholderProxyServer((string) $proxy['server'])) {
            Arr::forget($config, 'fetch.playwright.proxy');
        }

        $storageStatePath = Arr::get($config, 'fetch.playwright.storage_state_path');
        if (is_string($storageStatePath) && $this->isPlaceholderStorageStatePath($storageStatePath)) {
            Arr::forget($config, 'fetch.playwright.storage_state_path');
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function normalizeConfidence(mixed $value, float $fallback): float
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $normalized = (float) $value;

        return max(0.0, min(1.0, $normalized));
    }

    private function normalizeDomain(string $value): string
    {
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return '';
        }

        return trim(mb_strtolower($host));
    }

    private function isPlaceholderProxyServer(string $server): bool
    {
        $trimmed = trim($server);

        if ($trimmed === '') {
            return false;
        }

        $host = parse_url($trimmed, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return false;
        }

        $normalizedHost = mb_strtolower(trim($host));

        return $normalizedHost === 'proxy.example.com'
            || str_ends_with($normalizedHost, '.example.com');
    }

    private function isPlaceholderStorageStatePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', mb_strtolower(trim($path)));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'path/to/storage/state')
            || str_contains($normalized, '/path/to/')
            || str_contains($normalized, 'your-storage-state');
    }
}
