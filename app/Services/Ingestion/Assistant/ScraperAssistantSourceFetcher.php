<?php

namespace App\Services\Ingestion\Assistant;

use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Ingestion\Assistant\Agents\WebFetchHtmlAgent;
use InvalidArgumentException;
use Laravel\Ai\Providers\Tools\WebFetch;

class ScraperAssistantSourceFetcher
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
    ) {}

    /**
     * @return array{html: string, final_url: string, renderer: string, warnings: array<int, string>, used_webfetch: bool}
     */
    public function fetch(string $url): array
    {
        $normalizedUrl = trim($url);

        if ($normalizedUrl === '' || ! filter_var($normalizedUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('A valid source URL is required to fetch page content.');
        }

        $renderer = (string) config('scraper-assistant.fetch.renderer', 'auto');
        $warnings = [];

        $result = $this->pageFetcher->fetch($normalizedUrl, $renderer);

        if ($result !== null) {
            $html = $this->sanitizeHtml((string) ($result['body'] ?? ''));

            if ($html !== '') {
                return [
                    'html' => $html,
                    'final_url' => (string) ($result['url'] ?? $normalizedUrl),
                    'renderer' => (string) ($result['renderer'] ?? 'http'),
                    'warnings' => $warnings,
                    'used_webfetch' => false,
                ];
            }
        }

        $warnings[] = 'Primary HTTP/Playwright fetch returned no content.';

        $webFetchResult = $this->fetchViaWebFetch($normalizedUrl);

        if ($webFetchResult !== null) {
            $warnings = array_merge($warnings, $webFetchResult['warnings']);

            return [
                'html' => $webFetchResult['html'],
                'final_url' => $webFetchResult['final_url'] ?: $normalizedUrl,
                'renderer' => 'ai_webfetch',
                'warnings' => $warnings,
                'used_webfetch' => true,
            ];
        }

        throw new InvalidArgumentException('Unable to fetch source page content from the URL. Paste source HTML instead.');
    }

    /**
     * @return array{html: string, final_url: string|null, warnings: array<int, string>}|null
     */
    private function fetchViaWebFetch(string $url): ?array
    {
        if (! (bool) config('scraper-assistant.ai.webfetch_enabled', false)) {
            return null;
        }

        $provider = $this->resolveWebFetchProvider();

        if ($provider === null) {
            return null;
        }

        $domain = $this->normalizeDomain($url);

        if ($domain === '') {
            return null;
        }

        $agent = new WebFetchHtmlAgent([
            (new WebFetch)->max(1)->allow([$domain]),
        ]);

        try {
            $response = $agent->prompt(
                implode("\n", [
                    'Fetch this URL and return raw rendered HTML only.',
                    'URL:',
                    $url,
                ]),
                provider: [
                    $provider => (string) config('scraper-assistant.ai.webfetch_model', 'claude-3-5-haiku-latest'),
                ],
                timeout: (int) config('scraper-assistant.ai.timeout', 45),
            );
        } catch (\Throwable) {
            return null;
        }

        $structured = is_array($response->structured ?? null) ? $response->structured : [];
        $html = $this->sanitizeHtml((string) ($structured['html'] ?? ''));

        if ($html === '') {
            return null;
        }

        $warnings = collect($structured['warnings'] ?? [])
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (mixed $warning): string => trim((string) $warning))
            ->values()
            ->all();

        return [
            'html' => $html,
            'final_url' => is_string($structured['final_url'] ?? null) ? trim($structured['final_url']) : null,
            'warnings' => $warnings,
        ];
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

            $apiKey = (string) config('ai.providers.'.$provider.'.key', '');

            if ($apiKey !== '') {
                return $provider;
            }
        }

        return null;
    }

    private function sanitizeHtml(string $html): string
    {
        $maxChars = max(1, (int) config('scraper-assistant.fetch.max_html_chars', 250000));
        $value = str_replace("\0", '', $html);

        if (mb_strlen($value) > $maxChars) {
            $value = mb_substr($value, 0, $maxChars);
        }

        return trim($value);
    }

    private function normalizeDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return '';
        }

        return trim(mb_strtolower($host));
    }
}
