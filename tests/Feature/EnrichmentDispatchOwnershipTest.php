<?php

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Services\Extraction\EnrichmentDispatcher;
use App\Services\Extraction\EnrichmentFingerprint;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

it('does not let legacy or retry jobs clear a newer pending reservation', function () {
    Queue::fake();
    $article = Article::factory()->create();
    $dispatcher = app(EnrichmentDispatcher::class);
    expect($dispatcher->dispatch($article->id))->toBeTrue();
    $first = Queue::pushed(EnrichArticle::class)->first();
    $legacy = new EnrichArticle($article->id);
    (new UniqueLock($legacy->uniqueVia()))->release($legacy);
    $legacy->releasePendingReservation();
    expect($dispatcher->dispatch($article->id))->toBeFalse();

    // Once processing starts, permit exactly one follow-up for changed content.
    $first->releasePendingReservation();
    expect($dispatcher->dispatch($article->id))->toBeTrue();
    $first->releasePendingReservation();
    $first->failed(new RuntimeException('Late failure from old attempt'));
    expect($dispatcher->dispatch($article->id))->toBeFalse();
    Queue::assertPushed(EnrichArticle::class, 2);
});

it('skips an already completed input but queues a real content update', function () {
    Queue::fake();
    $article = Article::factory()->create();
    ArticleAnalysis::factory()->create(['article_id' => $article->id,
        'enrichment_input_hash' => app(EnrichmentFingerprint::class)->forArticle($article)]);
    $dispatcher = app(EnrichmentDispatcher::class);
    expect($dispatcher->dispatch($article->id))->toBeFalse();
    $article->update(['title' => 'Changed public hearing']);
    expect($dispatcher->dispatch($article->id))->toBeTrue();
    Queue::assertPushed(EnrichArticle::class, 1);
});

it('releases its reservation if enqueueing fails', function () {
    $article = Article::factory()->create();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    expect(fn () => app(EnrichmentDispatcher::class)->dispatch($article->id))
        ->toThrow(RuntimeException::class, 'Queue unavailable');
    $lock = (new EnrichArticle($article->id))->uniqueVia()->lock('enrichment-pending:'.$article->id, 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('retains the pending reservation while waiting for processing or retry cooldown', function (bool $cooldown) {
    Queue::fake();
    $article = Article::factory()->create();
    $dispatcher = app(EnrichmentDispatcher::class);
    $dispatcher->dispatch($article->id);
    $job = Queue::pushed(EnrichArticle::class)->first();
    $cache = $job->uniqueVia();
    $processing = $cache->lock('enrichment-processing:'.$article->id, 180);
    if ($cooldown) {
        $cache->put('enrichment-retry-after:'.$article->id, now()->addMinutes(5)->timestamp, 300);
    } else {
        $processing->get();
    }
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn ($delay) => $delay > 0));
    $job->setJob($queueJob);
    $enricher = Mockery::mock(App\Services\Extraction\Enricher::class);
    $enricher->shouldNotReceive('enrich');
    app()->instance(App\Services\Extraction\Enricher::class, $enricher);
    app()->call([$job, 'handle']);
    expect($dispatcher->dispatch($article->id))->toBeFalse();
    $processing->release();
})->with([false, true]);
