<?php

use App\Services\Ingestion\Assistant\ScraperConfigAiRefiner;

uses(Tests\TestCase::class);

it('removes rss-only keys from non-rss refined config payloads', function () {
    $refiner = app(ScraperConfigAiRefiner::class);

    $normalizeRefinedConfig = (fn (string $type, string $profile, array $heuristicConfig, array $refinedConfig): array => $this->normalizeRefinedConfig(
        $type,
        $profile,
        $heuristicConfig,
        $refinedConfig,
    ))->bindTo($refiner, ScraperConfigAiRefiner::class);

    $result = $normalizeRefinedConfig(
        'html',
        'generic_listing',
        [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => '.flex-feature-feed h4.headline a[href^="/20"]',
                'link_attr' => 'href',
                'max_links' => 25,
            ],
            'article' => [
                'content_selector' => '.article-body',
                'remove_selectors' => ['script', 'style', 'nav', 'footer'],
            ],
            'best_effort' => true,
        ],
        [
            'feed_url' => 'https://example.com/feed',
            'lang' => 'en',
            'max_items' => 25,
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'proxy' => [],
                ],
            ],
            'pdf' => [],
        ],
    );

    expect($result)->toHaveKey('profile', 'generic_listing')
        ->and($result)->not->toHaveKey('feed_url')
        ->and($result)->not->toHaveKey('lang')
        ->and($result)->not->toHaveKey('max_items')
        ->and($result)->not->toHaveKey('pdf')
        ->and($result['fetch']['renderer'])->toBe('playwright');
});

it('strips placeholder playwright proxy and storage state values from refined config', function () {
    $refiner = app(ScraperConfigAiRefiner::class);

    $normalizeRefinedConfig = (fn (string $type, string $profile, array $heuristicConfig, array $refinedConfig): array => $this->normalizeRefinedConfig(
        $type,
        $profile,
        $heuristicConfig,
        $refinedConfig,
    ))->bindTo($refiner, ScraperConfigAiRefiner::class);

    $result = $normalizeRefinedConfig(
        'html',
        'generic_listing',
        [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => 'article a',
                'link_attr' => 'href',
                'max_links' => 25,
            ],
            'article' => [
                'content_selector' => 'article',
                'remove_selectors' => ['script', 'style', 'nav', 'footer'],
            ],
            'best_effort' => true,
        ],
        [
            'fetch' => [
                'renderer' => 'playwright',
                'playwright' => [
                    'storage_state_path' => 'path/to/storage/state',
                    'proxy' => [
                        'server' => 'http://proxy.example.com:8080',
                        'username' => 'user',
                        'password' => 'pass',
                    ],
                ],
            ],
        ],
    );

    expect(data_get($result, 'fetch.playwright.storage_state_path'))->toBeNull()
        ->and(data_get($result, 'fetch.playwright.proxy'))->toBeNull()
        ->and(data_get($result, 'fetch.renderer'))->toBe('playwright');
});
