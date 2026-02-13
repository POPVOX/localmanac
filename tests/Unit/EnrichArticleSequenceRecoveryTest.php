<?php

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\City;
use App\Services\Analysis\ArticleExplainerProjector;
use App\Services\Analysis\CivicActionProjector;
use App\Services\Analysis\CivicRelevanceCalculator;
use App\Services\Analysis\ProcessTimelineProjector;
use App\Services\Articles\ArticleTextService;
use App\Services\Extraction\ClaimWriter;
use App\Services\Extraction\Enricher;
use App\Services\Extraction\ProjectionWriter;
use App\Services\Ingestion\PostgresSequenceSynchronizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as M;

uses(Tests\TestCase::class, RefreshDatabase::class);

afterEach(function () {
    M::close();
});

function makeEnrichmentDependencies(): array
{
    return [
        M::mock(Enricher::class),
        M::mock(ClaimWriter::class),
        M::mock(ProjectionWriter::class),
        M::mock(CivicActionProjector::class),
        M::mock(ProcessTimelineProjector::class),
        M::mock(ArticleExplainerProjector::class),
        M::mock(CivicRelevanceCalculator::class),
        M::mock(ArticleTextService::class),
    ];
}

/**
 * @return array<int, string>
 */
function enrichArticleSequenceTables(): array
{
    return [
        'article_analyses',
        'claims',
        'keywords',
        'article_keywords',
        'article_entities',
        'article_issue_areas',
        'civic_actions',
        'process_timeline_items',
        'article_explainers',
    ];
}

function recoverablePrimaryKeyViolation(string $constraint = 'article_analyses_pkey'): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException(
        'pgsql',
        'insert into "article_analyses"',
        [],
        new RuntimeException("duplicate key value violates unique constraint \"{$constraint}\"")
    );
}

it('retries enrichment once after recoverable sequence drift is repaired', function () {
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Recovery City',
        'slug' => 'recovery-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Recovery Article',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(enrichArticleSequenceTables())
        ->andReturn(true);

    $job = new class($article->id) extends EnrichArticle
    {
        public int $attempts = 0;

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
            $this->attempts++;

            if ($this->attempts === 1) {
                throw recoverablePrimaryKeyViolation();
            }
        }
    };

    [
        $enricher,
        $claimWriter,
        $projectionWriter,
        $projector,
        $processTimelineProjector,
        $articleExplainerProjector,
        $calculator,
        $articleTextService,
    ] = makeEnrichmentDependencies();

    $job->handle(
        $enricher,
        $claimWriter,
        $projectionWriter,
        $projector,
        $processTimelineProjector,
        $articleExplainerProjector,
        $calculator,
        $articleTextService,
        $synchronizer
    );

    expect($article->fresh())->not->toBeNull()
        ->and($job->attempts)->toBe(2);
});

it('does not retry on non-recoverable unique constraint errors', function () {
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'No Retry City',
        'slug' => 'no-retry-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'No Retry Article',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')->never();

    $job = new class($article->id) extends EnrichArticle
    {
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
            throw recoverablePrimaryKeyViolation('article_analyses_article_id_unique');
        }
    };

    [
        $enricher,
        $claimWriter,
        $projectionWriter,
        $projector,
        $processTimelineProjector,
        $articleExplainerProjector,
        $calculator,
        $articleTextService,
    ] = makeEnrichmentDependencies();

    expect(fn () => $job->handle(
        $enricher,
        $claimWriter,
        $projectionWriter,
        $projector,
        $processTimelineProjector,
        $articleExplainerProjector,
        $calculator,
        $articleTextService,
        $synchronizer
    ))->toThrow(UniqueConstraintViolationException::class);
});

it('fails when sequence drift is detected but not repaired', function () {
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Unrepaired City',
        'slug' => 'unrepaired-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Unrepaired Article',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    $synchronizer = M::mock(PostgresSequenceSynchronizer::class);
    $synchronizer->shouldReceive('syncTables')
        ->once()
        ->with(enrichArticleSequenceTables())
        ->andReturn(false);

    $job = new class($article->id) extends EnrichArticle
    {
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
            throw recoverablePrimaryKeyViolation('article_explainers_pkey');
        }
    };

    [
        $enricher,
        $claimWriter,
        $projectionWriter,
        $projector,
        $processTimelineProjector,
        $articleExplainerProjector,
        $calculator,
        $articleTextService,
    ] = makeEnrichmentDependencies();

    expect(fn () => $job->handle(
        $enricher,
        $claimWriter,
        $projectionWriter,
        $projector,
        $processTimelineProjector,
        $articleExplainerProjector,
        $calculator,
        $articleTextService,
        $synchronizer
    ))->toThrow(UniqueConstraintViolationException::class);
});
