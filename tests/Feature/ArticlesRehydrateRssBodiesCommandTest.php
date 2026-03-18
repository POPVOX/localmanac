<?php

use App\Jobs\EnrichArticle;
use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\RssCanonicalBodyHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('rehydrates teaser-only html articles and queues enrichment', function () {
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Updates',
        'slug' => 'updates',
        'type' => 'rss',
        'source_url' => 'https://example.com/feed',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'March 10 City Council Meeting recap',
        'summary' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/article-598',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_html' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'raw_text' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'cleaned_text' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/article-598',
        'source_type' => 'rss',
        'source_uid' => 'guid-598',
        'accessed_at' => now(),
    ]);

    $hydrator = \Mockery::mock(RssCanonicalBodyHydrator::class);
    $hydrator->shouldReceive('shouldHydrate')
        ->once()
        ->with(
            'Yesterday at the City Council meeting, the Council heard the following items:',
            'Yesterday at the City Council meeting, the Council heard the following items:',
            'Yesterday at the City Council meeting, the Council heard the following items:',
            'https://example.com/article-598',
        )
        ->andReturnTrue();
    $hydrator->shouldReceive('hydrate')
        ->once()
        ->with('https://example.com/article-598')
        ->andReturn([
            'canonical_url' => 'https://example.com/article-598',
            'raw_html' => '<main><p>Consent Agenda approved 7-0.</p><p>Board of Bids and Contracts approved 7-0.</p></main>',
            'raw_text' => "Consent Agenda approved 7-0.\n\nBoard of Bids and Contracts approved 7-0.",
            'cleaned_text' => "Consent Agenda approved 7-0.\n\nBoard of Bids and Contracts approved 7-0.",
            'title' => 'March 10 City Council Meeting recap',
            'renderer' => 'http',
        ]);

    Queue::fake();
    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan('articles:rehydrate-rss-bodies --limit=1')
        ->expectsOutputToContain('Repaired 1 of 1 candidate article(s). 1 rehydrated from canonical HTML.')
        ->assertExitCode(0);

    $article->refresh();
    $article->load('body');

    expect($article->body?->cleaned_text)
        ->toContain('Consent Agenda approved 7-0')
        ->toContain('Board of Bids and Contracts approved 7-0');

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($article) {
        return $job->articleId === $article->id;
    });
});

it('queues document extraction for document-like candidates', function () {
    Queue::fake();
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Doc City',
        'slug' => 'doc-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Detour Notice',
        'summary' => 'Routes will be detoured.',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/detour.pdf',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_html' => 'Routes will be detoured.',
        'raw_text' => 'Routes will be detoured.',
        'cleaned_text' => 'Routes will be detoured.',
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/detour.pdf',
        'source_type' => 'rss',
        'source_uid' => 'guid-doc',
        'accessed_at' => now(),
    ]);

    $hydrator = \Mockery::mock(RssCanonicalBodyHydrator::class);
    $hydrator->shouldReceive('shouldHydrate')->never();
    $hydrator->shouldReceive('hydrate')->never();

    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan('articles:rehydrate-rss-bodies')
        ->expectsOutputToContain('Repaired 1 of 1 candidate article(s). 1 queued for document extraction.')
        ->assertExitCode(0);

    Queue::assertPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($article) {
        return $job->articleId === $article->id
            && $job->pdfUrl === 'https://example.com/detour.pdf';
    });
});

it('queues ai enrichment for full-body articles with weak explainers', function () {
    Queue::fake();
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Explainer City',
        'slug' => 'explainer-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Commission votes on housing proposals',
        'summary' => 'Various items were discussed during the meeting.',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/housing',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_html' => '<main>'.str_repeat('Commissioners debated housing proposals and scheduled next steps. ', 20).'</main>',
        'raw_text' => str_repeat('Commissioners debated housing proposals and scheduled next steps. ', 20),
        'cleaned_text' => str_repeat('Commissioners debated housing proposals and scheduled next steps. ', 20),
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/housing',
        'source_type' => 'html',
        'source_uid' => 'guid-enrich',
        'accessed_at' => now(),
    ]);

    $hydrator = \Mockery::mock(RssCanonicalBodyHydrator::class);
    $hydrator->shouldReceive('shouldHydrate')
        ->once()
        ->andReturnFalse();
    $hydrator->shouldReceive('hydrate')->never();

    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan('articles:rehydrate-rss-bodies')
        ->expectsOutputToContain('Repaired 1 of 1 candidate article(s). 1 queued for AI enrichment.')
        ->assertExitCode(0);

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($article) {
        return $job->articleId === $article->id;
    });
});

it('reruns ai enrichment for a targeted article even when hydration is not needed', function () {
    Queue::fake();
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Target City',
        'slug' => 'target-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Targeted rerun article',
        'summary' => 'A complete summary already exists.',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/targeted',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_html' => '<main>'.str_repeat('This article body is already complete and specific. ', 20).'</main>',
        'raw_text' => str_repeat('This article body is already complete and specific. ', 20),
        'cleaned_text' => str_repeat('This article body is already complete and specific. ', 20),
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/targeted',
        'source_type' => 'html',
        'source_uid' => 'guid-targeted',
        'accessed_at' => now(),
    ]);

    $hydrator = \Mockery::mock(RssCanonicalBodyHydrator::class);
    $hydrator->shouldReceive('shouldHydrate')
        ->once()
        ->andReturnFalse();
    $hydrator->shouldReceive('hydrate')->never();

    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan("articles:rehydrate-rss-bodies --article={$article->id}")
        ->expectsOutputToContain('Repaired 1 of 1 article(s).')
        ->assertExitCode(0);

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($article) {
        return $job->articleId === $article->id;
    });
});

it('continues bulk repair when one candidate fails', function () {
    Queue::fake();
    config()->set('enrichment.enabled', true);

    $city = City::create([
        'name' => 'Failure City',
        'slug' => 'failure-city',
    ]);

    $firstArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'Broken source article',
        'summary' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/broken',
    ]);

    $secondArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'Recoverable source article',
        'summary' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/recoverable',
    ]);

    foreach ([$firstArticle, $secondArticle] as $article) {
        ArticleBody::create([
            'article_id' => $article->id,
            'raw_html' => 'Yesterday at the City Council meeting, the Council heard the following items:',
            'raw_text' => 'Yesterday at the City Council meeting, the Council heard the following items:',
            'cleaned_text' => 'Yesterday at the City Council meeting, the Council heard the following items:',
            'lang' => 'en',
            'extracted_at' => now(),
            'extraction_status' => 'success',
        ]);

        ArticleSource::create([
            'city_id' => $city->id,
            'article_id' => $article->id,
            'source_url' => $article->canonical_url,
            'source_type' => 'rss',
            'source_uid' => 'guid-'.$article->id,
            'accessed_at' => now(),
        ]);
    }

    $hydrator = \Mockery::mock(RssCanonicalBodyHydrator::class);
    $hydrator->shouldReceive('shouldHydrate')
        ->twice()
        ->andReturnTrue();
    $hydrator->shouldReceive('hydrate')
        ->once()
        ->with('https://example.com/broken')
        ->andThrow(new RuntimeException('Binary payload'));
    $hydrator->shouldReceive('hydrate')
        ->once()
        ->with('https://example.com/recoverable')
        ->andReturn([
            'canonical_url' => 'https://example.com/recoverable',
            'raw_html' => '<main><p>Consent Agenda approved 7-0.</p></main>',
            'raw_text' => 'Consent Agenda approved 7-0.',
            'cleaned_text' => 'Consent Agenda approved 7-0.',
            'title' => 'Recoverable source article',
            'renderer' => 'http',
        ]);

    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan('articles:rehydrate-rss-bodies')
        ->expectsOutputToContain('Repaired 1 of 2 candidate article(s). 1 rehydrated from canonical HTML; 1 failed.')
        ->assertExitCode(0);

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($secondArticle) {
        return $job->articleId === $secondArticle->id;
    });
});
