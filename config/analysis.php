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
        // Run LLM scoring for articles with sufficient extracted text.
        // Keeps costs low and avoids junk analysis on placeholder/empty bodies.
        'min_cleaned_text_chars' => 800,
    ],
];
