<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleOpportunity;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\HeuristicScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScoreArticleHeuristics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $articleId)
    {
        $this->onQueue('analysis');
    }

    public function handle(HeuristicScorer $scorer, CivicRelevanceCalculator $calculator): void
    {
        $article = Article::query()
            ->with(['body', 'sources', 'scraper.organization'])
            ->find($this->articleId);

        if (! $article) {
            return;
        }

        try {
            $result = $scorer->score($article);
            $dimensions = $result['dimensions'] ?? [];
            $signals = $result['signals'] ?? [];
            $opportunities = $result['opportunities'] ?? [];

            $finalScores = $calculator->finalScores($dimensions);
            $civicScore = $calculator->compute($finalScores);

            ArticleAnalysis::updateOrCreate(
                ['article_id' => $article->id],
                [
                    'score_version' => config('analysis.score_version', 'crf_v1'),
                    'status' => 'heuristics_done',
                    'heuristic_scores' => [
                        'dimensions' => $dimensions,
                        'signals' => $signals,
                    ],
                    'final_scores' => $finalScores,
                    'civic_relevance_score' => $civicScore,
                    'last_scored_at' => now(),
                ]
            );

            ArticleOpportunity::query()
                ->where('article_id', $article->id)
                ->where('source', 'heuristic')
                ->delete();

            foreach ($opportunities as $opportunity) {
                ArticleOpportunity::create(array_merge([
                    'article_id' => $article->id,
                    'source' => 'heuristic',
                ], $opportunity));
            }
        } catch (Throwable $exception) {
            ArticleAnalysis::updateOrCreate(
                ['article_id' => $article->id],
                [
                    'score_version' => config('analysis.score_version', 'crf_v1'),
                    'status' => 'failed',
                    'heuristic_scores' => [
                        'error' => $exception->getMessage(),
                    ],
                    'last_scored_at' => now(),
                ]
            );
        }
    }
}
