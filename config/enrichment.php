<?php

return [
    'enabled' => true,
    'provider' => 'openai',
    'model' => 'gpt-4o-mini',
    'prompt_version' => 'enrich_v1_prompt_002',
    'min_cleaned_text_chars' => 800,
    'max_text_chars' => 18000,
    'queue' => 'analysis',
    'http_timeout' => (int) env('ENRICHMENT_HTTP_TIMEOUT', 120),
    'http_retries' => (int) env('ENRICHMENT_HTTP_RETRIES', 2),
    'http_retry_sleep_ms' => (int) env('ENRICHMENT_HTTP_RETRY_SLEEP_MS', 250),
    'projections' => [
        'min_confidence' => 0.55,
    ],
];
