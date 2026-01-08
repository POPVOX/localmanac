<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Services\Analysis\ArticleExplainerProjector;
use App\Services\Analysis\CivicActionProjector;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\ProcessTimelineProjector;
use App\Services\Extraction\ClaimWriter;
use App\Services\Extraction\Enricher;
use App\Services\Extraction\ProjectionWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnrichArticle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $articleId)
    {
        $this->onQueue('analysis');
    }

    /**
     * Execute the job.
     */
    public function handle(
        Enricher $enricher,
        ClaimWriter $claimWriter,
        ProjectionWriter $projectionWriter,
        CivicActionProjector $projector,
        ProcessTimelineProjector $processTimelineProjector,
        ArticleExplainerProjector $articleExplainerProjector,
        CivicRelevanceCalculator $calculator
    ): void {
        $article = Article::query()
            ->with(['body', 'city', 'scraper.organization'])
            ->find($this->articleId);

        if (! $article) {
            return;
        }

        if (! config('enrichment.enabled', true)) {
            return;
        }

        $payload = $enricher->enrich($article);
        $analysis = is_array($payload['analysis'] ?? null) ? $payload['analysis'] : [];
        $enrichment = is_array($payload['enrichment'] ?? null) ? $payload['enrichment'] : [];
        $processTimeline = is_array($payload['process_timeline'] ?? null)
            ? $payload['process_timeline']
            : ['items' => [], 'current_key' => null];
        $explainer = is_array($payload['explainer'] ?? null) ? $payload['explainer'] : null;
        $dimensions = is_array($analysis['dimensions'] ?? null) ? $analysis['dimensions'] : [];
        $confidence = $analysis['confidence'] ?? ($payload['confidence'] ?? 0.0);
        $model = (string) config('enrichment.model', '');
        $promptVersion = (string) config('enrichment.prompt_version', '');
        $analysisPayload = array_merge($analysis, [
            'process_timeline' => $processTimeline,
            'explainer' => $explainer,
        ]);

        ArticleAnalysis::updateOrCreate(
            ['article_id' => $article->id],
            [
                'score_version' => config('analysis.score_version', 'crf_v1'),
                'status' => 'llm_done',
                'llm_scores' => $analysisPayload,
                'final_scores' => $analysisPayload,
                'civic_relevance_score' => $calculator->compute($dimensions),
                'model' => $model !== '' ? $model : null,
                'prompt_version' => $promptVersion !== '' ? $promptVersion : null,
                'confidence' => is_numeric($confidence) ? (float) $confidence : null,
                'last_scored_at' => now(),
            ]
        );

        $claimWriter->write($article, $enrichment, $model, $promptVersion);

        $projectionWriter->write($article);

        $article->load('analysis', 'city', 'scraper.organization');

        $projector->projectForArticle($article);
        $processTimelineProjector->projectForArticle($article, $payload);
        $articleExplainerProjector->projectForArticle($article, $payload);
    }
}
