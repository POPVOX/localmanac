<?php

namespace App\Services\Chat;

use App\Models\Article;
use App\Services\Analysis\ScoreDimensions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EvidenceSelector
{
    /**
     * @param  Collection<int, Article>  $articles
     * @return array<int, array<string, mixed>>
     */
    public function select(int $cityId, string $question, Collection $articles, int $limit = 5): array
    {
        if ($articles instanceof EloquentCollection) {
            $articles->loadMissing(['analysis', 'body', 'sources']);
        }

        $actionable = $this->isActionableQuestion($question);

        return $articles
            ->filter(fn (Article $article) => (int) $article->city_id === $cityId)
            ->map(fn (Article $article) => [
                'article' => $article,
                'score' => $this->scoreArticle($article, $actionable),
            ])
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->map(fn (array $entry) => $this->toEvidence($entry['article']))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toEvidence(Article $article): array
    {
        $analysis = $article->analysis;
        $finalScores = $analysis?->final_scores ?? [];

        return [
            'article_id' => $article->id,
            'title' => $article->title,
            'excerpt' => $this->excerpt($article),
            'source_url' => $article->primarySourceUrl(),
            'civic_relevance_score' => (float) ($analysis?->civic_relevance_score ?? 0.0),
            'agency' => (float) ($finalScores[ScoreDimensions::AGENCY] ?? 0.0),
            'timeliness' => (float) ($finalScores[ScoreDimensions::TIMELINESS] ?? 0.0),
            'citations' => [
                [
                    'source_url' => $article->primarySourceUrl(),
                ],
            ],
        ];
    }

    private function excerpt(Article $article): string
    {
        $text = $article->summary ?: $article->body?->cleaned_text ?: '';

        return Str::limit(trim($text), 280);
    }

    private function scoreArticle(Article $article, bool $actionable): float
    {
        $analysis = $article->analysis;
        $civic = (float) ($analysis?->civic_relevance_score ?? 0.0);
        $finalScores = $analysis?->final_scores ?? [];
        $agency = (float) ($finalScores[ScoreDimensions::AGENCY] ?? 0.0);
        $timeliness = (float) ($finalScores[ScoreDimensions::TIMELINESS] ?? 0.0);

        if (! $actionable) {
            return $civic;
        }

        return ($civic * 0.55) + ($agency * 0.25) + ($timeliness * 0.20);
    }

    private function isActionableQuestion(string $question): bool
    {
        $question = mb_strtolower($question);

        foreach (config('analysis.actionable_query_keywords', []) as $keyword) {
            if (str_contains($question, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
