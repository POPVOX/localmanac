<?php

return [
    'enabled' => (bool) env('SCRAPER_ASSISTANT_ENABLED', true),

    'fetch' => [
        'renderer' => env('SCRAPER_ASSISTANT_RENDERER', 'auto'),
        'max_html_chars' => (int) env('SCRAPER_ASSISTANT_MAX_HTML_CHARS', 1200000),
    ],

    'preview' => [
        'max_items' => (int) env('SCRAPER_ASSISTANT_PREVIEW_MAX_ITEMS', 5),
        'generic_listing' => [
            'max_links' => (int) env('SCRAPER_ASSISTANT_PREVIEW_GENERIC_MAX_LINKS', 6),
            'max_pages' => (int) env('SCRAPER_ASSISTANT_PREVIEW_GENERIC_MAX_PAGES', 1),
            'playwright_timeout_ms' => (int) env('SCRAPER_ASSISTANT_PREVIEW_GENERIC_PLAYWRIGHT_TIMEOUT_MS', 15000),
            'playwright_refresh_attempts' => (int) env('SCRAPER_ASSISTANT_PREVIEW_GENERIC_PLAYWRIGHT_REFRESH_ATTEMPTS', 1),
            'playwright_max_scroll_steps' => (int) env('SCRAPER_ASSISTANT_PREVIEW_GENERIC_PLAYWRIGHT_MAX_SCROLL_STEPS', 4),
            'playwright_scroll_pause_ms' => (int) env('SCRAPER_ASSISTANT_PREVIEW_GENERIC_PLAYWRIGHT_SCROLL_PAUSE_MS', 400),
        ],
    ],

    'html_defaults' => [
        'apply_fetch_hardening' => (bool) env('SCRAPER_ASSISTANT_HTML_APPLY_FETCH_HARDENING', true),
        'fetch_renderer' => env('SCRAPER_ASSISTANT_HTML_FETCH_RENDERER', 'auto'),
        'playwright' => [
            'storage_state_dir' => env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_STORAGE_STATE_DIR', 'storage/app/playwright'),
            'timeout_ms' => (int) env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_TIMEOUT_MS', 45000),
            'wait_selector' => env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_WAIT_SELECTOR', 'main'),
            'refresh_on_blocked' => (bool) env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_REFRESH_ON_BLOCKED', true),
            'refresh_attempts' => (int) env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_REFRESH_ATTEMPTS', 2),
            'auto_scroll' => (bool) env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_AUTO_SCROLL', true),
            'max_scroll_steps' => (int) env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_MAX_SCROLL_STEPS', 12),
            'scroll_pause_ms' => (int) env('SCRAPER_ASSISTANT_HTML_PLAYWRIGHT_SCROLL_PAUSE_MS', 500),
        ],
    ],

    'ai' => [
        'refine_enabled' => (bool) env('SCRAPER_ASSISTANT_AI_REFINE_ENABLED', true),
        'refine_html_always' => (bool) env('SCRAPER_ASSISTANT_AI_REFINE_HTML_ALWAYS', true),
        'refine_min_confidence' => (float) env('SCRAPER_ASSISTANT_AI_REFINE_MIN_CONFIDENCE', 0.75),
        'provider_chain' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SCRAPER_ASSISTANT_AI_PROVIDER_CHAIN', 'openai'))
        ))),
        'model' => env('SCRAPER_ASSISTANT_AI_MODEL', config('enrichment.model', 'gpt-4o-mini')),
        'timeout' => (int) env('SCRAPER_ASSISTANT_AI_TIMEOUT', 45),
        'webfetch_enabled' => (bool) env('SCRAPER_ASSISTANT_AI_WEBFETCH_ENABLED', false),
        'webfetch_provider_chain' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SCRAPER_ASSISTANT_AI_WEBFETCH_PROVIDER_CHAIN', 'anthropic,gemini'))
        ))),
        'webfetch_model' => env('SCRAPER_ASSISTANT_AI_WEBFETCH_MODEL', 'claude-3-5-haiku-latest'),
    ],
];
