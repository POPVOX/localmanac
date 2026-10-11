<?php

namespace App\Services\Extraction;

use App\Models\Article;

class EnrichmentFingerprint
{
    public function forArticle(Article $article): string
    {
        $article->loadMissing(['body', 'city', 'scraper.organization']);
        $text = trim((string) $article->body?->cleaned_text);

        return hash('sha256', json_encode([
            'text' => $text,
            'title' => $article->title,
            'fallback_summary' => $text === '' ? $article->summary : null,
            'published_at' => $article->published_at?->toIso8601String(),
            'city' => [$article->city_id, $article->city?->name, $article->city?->timezone],
            'organization' => $article->scraper?->organization?->name,
            'model' => config('enrichment.model'),
            'provider_chain' => config('enrichment.provider_chain'),
            'prompt_version' => config('enrichment.prompt_version'),
            'score_version' => config('analysis.score_version'),
            'max_text_chars' => config('enrichment.max_text_chars'),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
