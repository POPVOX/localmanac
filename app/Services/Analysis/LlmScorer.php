<?php

namespace App\Services\Analysis;

use App\Models\Article;
use RuntimeException;

class LlmScorer
{
    public const PROMPT_VERSION = 'crf_v1_prompt_001';

    /**
     * @return array{
     *     dimensions: array<string, float>,
     *     justifications: array<string, string>,
     *     opportunities: array<int, array<string, mixed>>,
     *     confidence: float,
     *     model: string
     * }
     */
    public function score(Article $article): array
    {
        if (! config('analysis.llm.enabled', false)) {
            throw new RuntimeException('LLM scoring is not enabled.');
        }

        throw new RuntimeException('LLM scoring client is not configured.');
    }
}
