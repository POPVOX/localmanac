<?php

namespace App\Services\Analysis;

use App\Models\Article;
use App\Models\ArticleAnalysis;

class AnalysisGate
{
    public function shouldRunLlm(Article $article, ArticleAnalysis $analysis): bool
    {
        // Global kill switch for LLM scoring.
        if (! (bool) config('analysis.llm.enabled', false)) {
            return false;
        }

        $article->loadMissing('body', 'scraper.organization');

        // Wichita V1: run LLM scoring broadly, but only when we have enough extracted text
        // to avoid spending tokens on empty/placeholder bodies.
        $minChars = (int) config('analysis.llm.min_cleaned_text_chars', 800);
        $cleaned = (string) ($article->body?->cleaned_text ?? '');
        $length = mb_strlen(trim($cleaned));

        if ($length < $minChars) {
            return false;
        }

        return true;
    }
}
