<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleOpportunity;
use App\Services\Analysis\AnalysisGate;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\LlmScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScoreArticleWithLlm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $articleId)
    {
        $this->onQueue('analysis');
    }

    public function handle(
        AnalysisGate $gate,
        LlmScorer $scorer,
        CivicRelevanceCalculator $calculator
    ): void {
        $article = Article::query()
            ->with(['analysis', 'body', 'sources', 'scraper.organization'])
            ->find($this->articleId);

        if (! $article || ! $article->analysis) {
            return;
        }

        if (! $gate->shouldRunLlm($article, $article->analysis)) {
            return;
        }

        try {
            $promptVersion = config('analysis.prompt_version', LlmScorer::PROMPT_VERSION);
            $result = $scorer->score($article);
            $dimensions = $result['dimensions'] ?? [];
            $justifications = $result['justifications'] ?? [];
            $opportunities = $result['opportunities'] ?? [];
            $confidence = (float) ($result['confidence'] ?? 0.0);
            $model = (string) ($result['model'] ?? '');

            $finalScores = $calculator->finalScores($dimensions);
            $civicScore = $calculator->compute($finalScores);

            ArticleAnalysis::updateOrCreate(
                ['article_id' => $article->id],
                [
                    'score_version' => config('analysis.score_version', 'crf_v1'),
                    'status' => 'llm_done',
                    'llm_scores' => [
                        'dimensions' => $dimensions,
                        'justifications' => $justifications,
                        'opportunities' => $opportunities,
                        'confidence' => $confidence,
                        'model' => $model,
                        'prompt_version' => $promptVersion,
                    ],
                    'final_scores' => $finalScores,
                    'civic_relevance_score' => $civicScore,
                    'model' => $model !== '' ? $model : null,
                    'prompt_version' => $promptVersion,
                    'confidence' => $confidence > 0 ? $confidence : null,
                    'last_scored_at' => now(),
                ]
            );

            ArticleOpportunity::query()
                ->where('article_id', $article->id)
                ->where('source', 'llm')
                ->delete();

            foreach ($opportunities as $opportunity) {
                ArticleOpportunity::create(array_merge([
                    'article_id' => $article->id,
                    'source' => 'llm',
                ], $opportunity));
            }
        } catch (Throwable $exception) {
            ArticleAnalysis::updateOrCreate(
                ['article_id' => $article->id],
                [
                    'score_version' => config('analysis.score_version', 'crf_v1'),
                    'status' => 'failed',
                    'llm_scores' => [
                        'error' => $exception->getMessage(),
                    ],
                    'last_scored_at' => now(),
                ]
            );
        }
    }
}
