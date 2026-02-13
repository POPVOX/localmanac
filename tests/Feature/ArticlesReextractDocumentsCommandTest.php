<?php

use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use App\Models\City;
use Illuminate\Support\Facades\Queue;

it('queues extraction jobs for document-like articles', function () {
    Queue::fake();

    $city = City::create(['name' => 'Doc City', 'slug' => 'doc-city']);

    $pdfArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'PDF Article',
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://example.com/one',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $pdfArticle->id,
        'source_url' => 'https://example.com/one',
        'source_type' => 'pdf',
    ]);

    $docxSourceArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'DOCX Article',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/two',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $docxSourceArticle->id,
        'source_url' => 'https://example.com/two.docx',
        'source_type' => 'docx',
    ]);

    $nonDocumentArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'News Article',
        'status' => 'published',
        'content_type' => 'news',
        'canonical_url' => 'https://example.com/three',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $nonDocumentArticle->id,
        'source_url' => 'https://example.com/three',
        'source_type' => 'rss',
    ]);

    $this->artisan('articles:reextract-documents')->assertExitCode(0);

    Queue::assertPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($pdfArticle): bool {
        return $job->articleId === $pdfArticle->id;
    });

    Queue::assertPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($docxSourceArticle): bool {
        return $job->articleId === $docxSourceArticle->id;
    });

    Queue::assertNotPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($nonDocumentArticle): bool {
        return $job->articleId === $nonDocumentArticle->id;
    });
});

it('respects the failed-only option', function () {
    Queue::fake();

    $city = City::create(['name' => 'Failed City', 'slug' => 'failed-city']);

    $failedArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'Failed Doc',
        'status' => 'published',
        'content_type' => 'docx',
        'canonical_url' => 'https://example.com/failed',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $failedArticle->id,
        'source_url' => 'https://example.com/failed.docx',
        'source_type' => 'docx',
    ]);

    ArticleBody::create([
        'article_id' => $failedArticle->id,
        'extracted_at' => now(),
        'extraction_status' => 'failed',
        'extraction_error' => 'Non-PDF response detected',
    ]);

    $successArticle = Article::create([
        'city_id' => $city->id,
        'title' => 'Succeeded Doc',
        'status' => 'published',
        'content_type' => 'docx',
        'canonical_url' => 'https://example.com/success',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $successArticle->id,
        'source_url' => 'https://example.com/success.docx',
        'source_type' => 'docx',
    ]);

    ArticleBody::create([
        'article_id' => $successArticle->id,
        'extracted_at' => now(),
        'extraction_status' => 'success',
        'cleaned_text' => 'Already extracted',
    ]);

    $this->artisan('articles:reextract-documents --failed-only')->assertExitCode(0);

    Queue::assertPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($failedArticle): bool {
        return $job->articleId === $failedArticle->id;
    });

    Queue::assertNotPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($successArticle): bool {
        return $job->articleId === $successArticle->id;
    });
});

it('respects city and limit options', function () {
    Queue::fake();

    $cityOne = City::create(['name' => 'Alpha City', 'slug' => 'alpha-city']);
    $cityTwo = City::create(['name' => 'Beta City', 'slug' => 'beta-city']);

    $firstAlpha = Article::create([
        'city_id' => $cityOne->id,
        'title' => 'Alpha One',
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://example.com/a1.pdf',
    ]);

    ArticleSource::create([
        'city_id' => $cityOne->id,
        'article_id' => $firstAlpha->id,
        'source_url' => 'https://example.com/a1.pdf',
        'source_type' => 'pdf',
    ]);

    $secondAlpha = Article::create([
        'city_id' => $cityOne->id,
        'title' => 'Alpha Two',
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://example.com/a2.pdf',
    ]);

    ArticleSource::create([
        'city_id' => $cityOne->id,
        'article_id' => $secondAlpha->id,
        'source_url' => 'https://example.com/a2.pdf',
        'source_type' => 'pdf',
    ]);

    $betaArticle = Article::create([
        'city_id' => $cityTwo->id,
        'title' => 'Beta One',
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://example.com/b1.pdf',
    ]);

    ArticleSource::create([
        'city_id' => $cityTwo->id,
        'article_id' => $betaArticle->id,
        'source_url' => 'https://example.com/b1.pdf',
        'source_type' => 'pdf',
    ]);

    $this->artisan('articles:reextract-documents --city=alpha-city --limit=1')->assertExitCode(0);

    Queue::assertPushedTimes(ExtractPdfBody::class, 1);
    Queue::assertPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($firstAlpha): bool {
        return $job->articleId === $firstAlpha->id;
    });
    Queue::assertNotPushed(ExtractPdfBody::class, function (ExtractPdfBody $job) use ($secondAlpha, $betaArticle): bool {
        return in_array($job->articleId, [$secondAlpha->id, $betaArticle->id], true);
    });
});
