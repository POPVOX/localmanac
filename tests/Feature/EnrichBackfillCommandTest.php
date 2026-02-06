<?php

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\City;
use Illuminate\Support\Facades\Queue;

it('queues enrichment for articles with enough text', function () {
    config()->set('enrichment.min_cleaned_text_chars', 10);

    $city = City::create([
        'name' => 'Backfill City',
        'slug' => 'backfill-city',
    ]);

    $eligible = Article::create([
        'city_id' => $city->id,
        'title' => 'Eligible Article',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $eligible->id,
        'cleaned_text' => str_repeat('Eligible text ', 3),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $ineligible = Article::create([
        'city_id' => $city->id,
        'title' => 'Too Short',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $ineligible->id,
        'cleaned_text' => 'short',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    Queue::fake();

    $this->artisan('enrich:backfill')->assertExitCode(0);

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($eligible) {
        return $job->articleId === $eligible->id;
    });

    Queue::assertNotPushed(EnrichArticle::class, function (EnrichArticle $job) use ($ineligible) {
        return $job->articleId === $ineligible->id;
    });
});
