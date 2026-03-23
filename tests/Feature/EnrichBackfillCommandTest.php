<?php

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\City;
use Illuminate\Support\Facades\Queue;

it('queues enrichment for articles with non-empty text', function () {
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

    $shortButEligible = Article::create([
        'city_id' => $city->id,
        'title' => 'Short Text',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $shortButEligible->id,
        'cleaned_text' => 'short',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $ineligible = Article::create([
        'city_id' => $city->id,
        'title' => 'Empty Text',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $ineligible->id,
        'cleaned_text' => '   ',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    Queue::fake();

    $this->artisan('enrich:backfill')->assertExitCode(0);

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($eligible) {
        return $job->articleId === $eligible->id;
    });

    Queue::assertPushed(EnrichArticle::class, function (EnrichArticle $job) use ($shortButEligible) {
        return $job->articleId === $shortButEligible->id;
    });

    Queue::assertNotPushed(EnrichArticle::class, function (EnrichArticle $job) use ($ineligible) {
        return $job->articleId === $ineligible->id;
    });
});

it('queues the newest eligible articles first when latest is provided', function () {
    $city = City::create([
        'name' => 'Backfill City',
        'slug' => 'backfill-city',
    ]);

    $oldest = Article::create([
        'city_id' => $city->id,
        'title' => 'Oldest',
        'status' => 'published',
        'content_type' => 'html',
        'published_at' => now()->subDays(3),
    ]);

    $middle = Article::create([
        'city_id' => $city->id,
        'title' => 'Middle',
        'status' => 'published',
        'content_type' => 'html',
        'published_at' => now()->subDays(2),
    ]);

    $newest = Article::create([
        'city_id' => $city->id,
        'title' => 'Newest',
        'status' => 'published',
        'content_type' => 'html',
        'published_at' => now()->subDay(),
    ]);

    foreach ([$oldest, $middle, $newest] as $article) {
        ArticleBody::create([
            'article_id' => $article->id,
            'cleaned_text' => 'Eligible text',
            'extracted_at' => now(),
            'extraction_status' => 'success',
        ]);
    }

    Queue::fake();

    $this->artisan('enrich:backfill --latest=2')->assertExitCode(0);

    expect(collect(Queue::pushed(EnrichArticle::class))
        ->map(fn (EnrichArticle $job): int => $job->articleId)
        ->values()
        ->all())
        ->toBe([$newest->id, $middle->id]);
});
