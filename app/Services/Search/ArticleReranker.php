<?php

namespace App\Services\Search;

use App\Models\Article;
use App\Services\Analysis\ScoreDimensions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ArticleReranker
{
    /**
     * @param  Collection<int, Article>  $articles
     * @return Collection<int, Article>
     */
    public function rerank(Collection $articles, string $query): Collection
    {
        if ($articles instanceof EloquentCollection) {
            $articles->loadMissing('analysis');
        }

        $actionable = $this->isActionableQuery($query);

        return $articles
            ->sortByDesc(fn (Article $article) => $this->scoreArticle($article, $actionable))
            ->values();
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

        return ($civic * 0.6) + ($agency * 0.25) + ($timeliness * 0.15);
    }

    private function isActionableQuery(string $query): bool
    {
        $query = mb_strtolower($query);

        foreach (config('analysis.actionable_query_keywords', []) as $keyword) {
            if (str_contains($query, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
