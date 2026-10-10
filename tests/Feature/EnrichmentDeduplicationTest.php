<?php

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleBody;
use App\Services\Analysis\ArticleExplainerProjector;
use App\Services\Analysis\CivicActionProjector;
use App\Services\Analysis\ProcessTimelineProjector;
use App\Services\Articles\ArticleTextService;
use App\Services\Chat\Ingestion\ArticleChunkEmbedder;
use App\Services\Extraction\ClaimWriter;
use App\Services\Extraction\Enricher;
use App\Services\Extraction\ProjectionWriter;
use Illuminate\Support\Facades\Queue;

function fakeEnrichmentSideEffects(): void
{
    foreach ([ClaimWriter::class => 'write', ProjectionWriter::class => 'write',
        CivicActionProjector::class => 'projectForArticle', ProcessTimelineProjector::class => 'projectForArticle',
        ArticleExplainerProjector::class => 'projectForArticle', ArticleChunkEmbedder::class => 'embed',
        ArticleTextService::class => 'refresh'] as $class => $method) {
        $mock = Mockery::mock($class);
        $mock->shouldReceive($method)->andReturn($class === ArticleTextService::class ? false : null);
        app()->instance($class, $mock);
    }
}

it('coalesces pending jobs for one article but allows different articles', function () {
    Queue::fake();
    EnrichArticle::dispatch(10);
    EnrichArticle::dispatch(10);
    EnrichArticle::dispatch(11);

    Queue::assertPushed(EnrichArticle::class, 2);
});

it('skips completed duplicate jobs while processing changed content and prompt versions', function () {
    fakeEnrichmentSideEffects();
    $article = Article::factory()->create();
    ArticleBody::factory()->create(['article_id' => $article->id, 'cleaned_text' => 'The city will hold a budget hearing.']);
    $enricher = Mockery::mock(Enricher::class);
    $enricher->shouldReceive('enrich')->times(3)->andReturn(['_complete' => true, 'analysis' => ['dimensions' => []]]);
    app()->instance(Enricher::class, $enricher);

    foreach (range(1, 3) as $_) {
        app()->call([new EnrichArticle($article->id), 'handle']);
    }
    expect(ArticleAnalysis::where('article_id', $article->id)->value('enrichment_input_hash'))->toHaveLength(64);

    $article->body()->update(['cleaned_text' => 'The city moved the budget hearing to Friday.']);
    app()->call([new EnrichArticle($article->id), 'handle']);
    app()->call([new EnrichArticle($article->id), 'handle']);
    config(['enrichment.prompt_version' => 'changed-prompt']);
    app()->call([new EnrichArticle($article->id), 'handle']);
});

it('does not mark incomplete provider work as complete and releases its lock for retry', function () {
    fakeEnrichmentSideEffects();
    $article = Article::factory()->create();
    $enricher = Mockery::mock(Enricher::class);
    $enricher->shouldReceive('enrich')->once()->andReturn(['_complete' => false]);
    app()->instance(Enricher::class, $enricher);
    expect(fn () => app()->call([new EnrichArticle($article->id), 'handle']))->toThrow(RuntimeException::class);
    expect(ArticleAnalysis::count())->toBe(0);

    $lock = (new EnrichArticle($article->id))->uniqueVia()->lock('enrichment-processing:'.$article->id, 180);
    expect($lock->get())->toBeTrue();
    $lock->release();

    $duplicate = new EnrichArticle($article->id);
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn ($delay) => $delay > 0 && $delay <= 300));
    $duplicate->setJob($queueJob);
    app()->call([$duplicate, 'handle']);
});

it('defers a duplicate already being processed without invoking the provider', function () {
    $article = Article::factory()->create();
    $job = new EnrichArticle($article->id);
    $lock = $job->uniqueVia()->lock('enrichment-processing:'.$article->id, 180);
    $lock->get();
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(30);
    $job->setJob($queueJob);
    $enricher = Mockery::mock(Enricher::class);
    $enricher->shouldNotReceive('enrich');
    app()->instance(Enricher::class, $enricher);
    app()->call([$job, 'handle']);
    $lock->release();
});

it('does not mark a concurrent content update as already processed', function () {
    fakeEnrichmentSideEffects();
    $article = Article::factory()->create();
    ArticleBody::factory()->create(['article_id' => $article->id, 'cleaned_text' => 'Original hearing notice.']);
    $enricher = Mockery::mock(Enricher::class);
    $calls = 0;
    $enricher->shouldReceive('enrich')->twice()->andReturnUsing(function () use ($article, &$calls) {
        if (++$calls === 1) {
            $article->body()->update(['cleaned_text' => 'Updated hearing notice.']);
        }

        return ['_complete' => true, 'analysis' => ['dimensions' => []]];
    });
    app()->instance(Enricher::class, $enricher);
    app()->call([new EnrichArticle($article->id), 'handle']);
    app()->call([new EnrichArticle($article->id), 'handle']);
    app()->call([new EnrichArticle($article->id), 'handle']);
});
