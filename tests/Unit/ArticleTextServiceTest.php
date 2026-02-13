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

it('preserves short meaningful titles', function () {
    $city = City::create([
        'name' => 'Calendar City',
        'slug' => 'calendar-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Bid Opening',
        'summary' => 'Event date: February 27, 2026. Event Time: 10:00 AM - 10:30 AM.',
        'status' => 'published',
        'content_type' => 'news',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)->toBe('Bid Opening');
});

it('derives legal notice titles from project lines in extracted text', function () {
    $city = City::create([
        'name' => 'Legal City',
        'slug' => 'legal-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '458-2022-085515_LegalNotice (PDF)',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => implode("\n", [
            'PROJ # 458-2022-085515',
            'Published on the City\'s Website on Friday, January 30, 2026',
            'SEALED PROPOSALS',
            'Notice is hereby given that bids will be received up to 10:00 a.m.',
            'for the following project:',
            'SWS #774 Repairs 13th St N & I-135 and 13th St N & Pennsylvania',
            'All bids received will thereafter be publicly opened.',
        ]),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('SWS #774 Repairs 13th St N & I-135 and 13th St N & Pennsylvania')
        ->and($article->summary)->not->toBeNull();
});

it('does not keep city website publication boilerplate as title', function () {
    $city = City::create([
        'name' => 'Boilerplate City',
        'slug' => 'boilerplate-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'PROJ # R0003 Published on the City\'s Website on Friday',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => implode("\n", [
            'PROJ # R0003',
            'Published on the City\'s Website on Friday, February 13, 2026',
            'SEALED PROPOSALS',
            'for the following project:',
            'L.W. Clapp Memorial Park Cross-Country Course Improvements',
        ]),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('L.W. Clapp Memorial Park Cross-Country Course Improvements')
        ->and($article->title)->not->toContain('Published on the City');
});
