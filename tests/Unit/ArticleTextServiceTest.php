<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Models\City;
use App\Services\Articles\ArticleTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('uses explainer summaries when the current summary is truncated', function () {
    $city = City::create([
        'name' => 'Summary City',
        'slug' => 'summary-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Council Preview',
        'summary' => 'Existing summary...',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('City council will consider a proposal. ', 20),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'whats_happening' => 'City council will vote on a downtown redevelopment proposal this week.',
        'why_it_matters' => 'The plan would reshape the downtown corridor.',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->summary)
        ->toBe('City council will vote on a downtown redevelopment proposal this week.')
        ->and($article->title)
        ->toBe('Council Preview');
});

it('derives a cleaner title from the summary when the title is file-like', function () {
    $city = City::create([
        'name' => 'Title City',
        'slug' => 'title-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '448-2024-029153_LegalNotice (PDF)',
        'summary' => 'The city is seeking sealed proposals for the bridge rehabilitation project.',
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('City seeks sealed proposals for the bridge rehabilitation project');
});

it('prefers the LLM headline when available', function () {
    $city = City::create([
        'name' => 'Headline City',
        'slug' => 'headline-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'The City of Wichita is inviting sealed proposals for a project to serve the Whispering',
        'summary' => 'Existing summary...',
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'whats_happening' => 'Sealed proposals are due for a project serving the Whispering Creek Addition.',
        'why_it_matters' => 'The project will upgrade local infrastructure.',
        'source_payload' => [
            'headline' => 'Sealed proposals sought for Whispering Creek Addition project',
        ],
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('Sealed proposals sought for Whispering Creek Addition project');
});
