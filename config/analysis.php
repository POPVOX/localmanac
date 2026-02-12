<?php

return [
    'score_version' => 'crf_v1',
    'prompt_version' => 'crf_v1_prompt_001',
    'jargon' => [
        'right-of-way',
        'easement',
        'variance',
        'zoning',
        'ad valorem',
        'bond issuance',
        'rfp',
        'rfq',
        'municipal code',
    ],
    'actionable_query_keywords' => [
        'what can i do',
        'how do i',
        'how can i',
        'comment',
        'submit',
        'register',
        'sign up',
        'meeting',
        'hearing',
        'deadline',
        'apply',
    ],
    'llm' => [
        'enabled' => true,
        'provider' => env('ANALYSIS_LLM_PROVIDER', 'openai'),
        'provider_chain' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ANALYSIS_LLM_PROVIDER_CHAIN', env('ANALYSIS_LLM_PROVIDER', 'openai')))
        ))),
        'model' => env('ANALYSIS_LLM_MODEL', config('enrichment.model', 'gpt-4o-mini')),
        'timeout' => (int) env('ANALYSIS_LLM_TIMEOUT', 120),
        // Run LLM scoring for articles with sufficient extracted text.
        // Keeps costs low and avoids junk analysis on placeholder/empty bodies.
        'min_cleaned_text_chars' => 800,
    ],
];
