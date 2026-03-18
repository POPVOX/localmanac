<?php

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Scraper;
use App\Services\Ingestion\RssCanonicalBodyHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('rehydrates teaser-only rss articles from their canonical page', function () {
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

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'whats_happening' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'why_it_matters' => null,
        'source' => 'analysis_llm',
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
            'cleaned_text' => "Yesterday at the City Council meeting, the Council heard the following items:\n\n* Consent Agenda approved 7-0\n\n* Board of Bids and Contracts approved 7-0",
            'title' => 'March 10 City Council Meeting recap',
            'renderer' => 'http',
        ]);

    Queue::fake();
    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan('articles:rehydrate-rss-bodies --limit=1')
        ->expectsOutputToContain('Rehydrated 1 of 1 candidate article(s).')
        ->assertExitCode(0);

    $article->refresh();
    $article->load(['body', 'explainer']);

    expect($article->body?->cleaned_text)
        ->toContain('Consent Agenda approved 7-0')
        ->toContain('Board of Bids and Contracts approved 7-0');

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($article) {
        return $job->articleId === $article->id;
    });
});

it('reruns ai enrichment for a targeted rss article even when hydration is no longer needed', function () {
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
        'raw_html' => '<main><p>Consent Agenda approved 7-0.</p><p>Board of Bids and Contracts approved 7-0.</p></main>',
        'raw_text' => "Consent Agenda approved 7-0.\n\nBoard of Bids and Contracts approved 7-0.",
        'cleaned_text' => "Consent Agenda approved 7-0.\n\nBoard of Bids and Contracts approved 7-0.",
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
        ->andReturnFalse();
    $hydrator->shouldReceive('hydrate')->never();

    Queue::fake();
    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan("articles:rehydrate-rss-bodies --article={$article->id}")
        ->expectsOutputToContain('Rehydrated 1 of 1 article(s).')
        ->assertExitCode(0);

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($article) {
        return $job->articleId === $article->id;
    });
});

it('continues bulk rehydration when one rss article fails', function () {
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

    $completeArticle = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Already complete article',
        'summary' => 'Existing summary',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/complete',
    ]);

    $firstArticle = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Broken source article',
        'summary' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/broken',
    ]);

    $secondArticle = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Recoverable source article',
        'summary' => 'Yesterday at the City Council meeting, the Council heard the following items:',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/recoverable',
    ]);

    ArticleBody::create([
        'article_id' => $completeArticle->id,
        'raw_html' => '<main>'.str_repeat('Completed article body. ', 30).'</main>',
        'raw_text' => str_repeat('Completed article body. ', 30),
        'cleaned_text' => str_repeat('Completed article body. ', 30),
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    foreach ([$completeArticle, $firstArticle, $secondArticle] as $article) {
        if ($article->is($completeArticle)) {
            ArticleSource::create([
                'city_id' => $city->id,
                'article_id' => $article->id,
                'source_url' => $article->canonical_url,
                'source_type' => 'rss',
                'source_uid' => 'guid-'.$article->id,
                'accessed_at' => now(),
            ]);

            continue;
        }

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

    Queue::fake();
    app()->instance(RssCanonicalBodyHydrator::class, $hydrator);

    $this->artisan('articles:rehydrate-rss-bodies')
        ->expectsOutputToContain('Rehydrated 1 of 2 candidate article(s). Skipped 1 already complete. 1 could not be hydrated.')
        ->assertExitCode(0);

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($secondArticle) {
        return $job->articleId === $secondArticle->id;
    });
});
