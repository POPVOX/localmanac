<?php

return [
    'enabled' => (bool) env('SCRAPER_ASSISTANT_ENABLED', true),

    'fetch' => [
        'renderer' => env('SCRAPER_ASSISTANT_RENDERER', 'auto'),
        'max_html_chars' => (int) env('SCRAPER_ASSISTANT_MAX_HTML_CHARS', 250000),
    ],

    'preview' => [
        'max_items' => (int) env('SCRAPER_ASSISTANT_PREVIEW_MAX_ITEMS', 5),
    ],

    'ai' => [
        'refine_enabled' => (bool) env('SCRAPER_ASSISTANT_AI_REFINE_ENABLED', false),
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
