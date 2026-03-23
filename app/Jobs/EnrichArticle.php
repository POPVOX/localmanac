<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Services\Analysis\ArticleExplainerProjector;
use App\Services\Analysis\CivicActionProjector;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\ProcessTimelineProjector;
use App\Services\Articles\ArticleTextService;
use App\Services\Extraction\ClaimWriter;
use App\Services\Extraction\Enricher;
use App\Services\Extraction\ProjectionWriter;
use App\Services\Ingestion\PostgresSequenceSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        $queue = trim((string) config('enrichment.queue', 'analysis'));

        $this->onQueue($queue !== '' ? $queue : 'analysis');
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
        CivicRelevanceCalculator $calculator,
        ArticleTextService $articleTextService,
        PostgresSequenceSynchronizer $sequenceSynchronizer
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

        try {
            $this->processArticle(
                $article,
                $enricher,
                $claimWriter,
                $projectionWriter,
                $projector,
                $processTimelineProjector,
                $articleExplainerProjector,
                $calculator,
                $articleTextService
            );
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isRecoverablePrimaryKeyViolation($exception)) {
                throw $exception;
            }

            $recovered = $this->resolveSequenceDrift($sequenceSynchronizer);

            if (! $recovered) {
                throw $exception;
            }

            Log::warning('Recovered from Postgres sequence drift while enriching article.', [
                'article_id' => $article->id,
            ]);

            $this->processArticle(
                $article,
                $enricher,
                $claimWriter,
                $projectionWriter,
                $projector,
                $processTimelineProjector,
                $articleExplainerProjector,
                $calculator,
                $articleTextService
            );
        }
    }

    protected function processArticle(
        Article $article,
        Enricher $enricher,
        ClaimWriter $claimWriter,
        ProjectionWriter $projectionWriter,
        CivicActionProjector $projector,
        ProcessTimelineProjector $processTimelineProjector,
        ArticleExplainerProjector $articleExplainerProjector,
        CivicRelevanceCalculator $calculator,
        ArticleTextService $articleTextService
    ): void {
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
                'coverage_scope' => is_string($analysis['coverage_scope'] ?? null)
                    ? $analysis['coverage_scope']
                    : null,
                'local_relevance_score' => is_numeric($analysis['local_relevance_score'] ?? null)
                    ? (float) $analysis['local_relevance_score']
                    : null,
                'locality_reason' => is_string($analysis['locality_reason'] ?? null)
                    ? trim($analysis['locality_reason'])
                    : null,
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
        $articleTextService->refresh($article);
    }

    private function isRecoverablePrimaryKeyViolation(UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (! str_contains($message, 'duplicate key value violates unique constraint')) {
            return false;
        }

        foreach ($this->recoverablePrimaryKeys() as $primaryKey) {
            if (str_contains($message, "\"{$primaryKey}\"")) {
                return true;
            }
        }

        return false;
    }

    private function resolveSequenceDrift(PostgresSequenceSynchronizer $sequenceSynchronizer): bool
    {
        return $sequenceSynchronizer->syncTables([
            'article_analyses',
            'claims',
            'keywords',
            'article_keywords',
            'article_entities',
            'article_issue_areas',
            'civic_actions',
            'process_timeline_items',
            'article_explainers',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function recoverablePrimaryKeys(): array
    {
        return [
            'article_analyses_pkey',
            'claims_pkey',
            'keywords_pkey',
            'article_keywords_pkey',
            'article_entities_pkey',
            'article_issue_areas_pkey',
            'civic_actions_pkey',
            'process_timeline_items_pkey',
            'article_explainers_pkey',
        ];
    }
}
