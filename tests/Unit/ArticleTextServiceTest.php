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

it('replaces weak meeting summaries with the fallback narrative', function () {
    $city = City::create([
        'name' => 'Meeting City',
        'slug' => 'meeting-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'March 10 City Council Meeting recap',
        'summary' => 'During the March 10 City Council meeting, various items were discussed. The Council focused on important local issues affecting Wichita residents.',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => implode("\n\n", [
            'Yesterday at the City Council meeting, the Council heard the following items:',
            '* Consent Agenda with the exception of item 6 – Approved 7/0',
            '* Board of Bids and Contracts – Approved 7/0',
            '* Petitions for Public Improvements – Approved 7/0',
        ]),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'whats_happening' => 'During the March 10 City Council meeting, various items were discussed. The Council focused on important local issues affecting Wichita residents.',
        'why_it_matters' => 'Understanding the outcomes of these meetings helps residents stay informed about community decisions and local governance.',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->summary)
        ->toContain('Consent Agenda with the exception of item 6')
        ->toContain('Board of Bids and Contracts')
        ->not->toContain('various items were discussed');
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

it('treats legal publish boilerplate titles as weak and replaces them from summary text', function () {
    $city = City::create([
        'name' => 'Publish City',
        'slug' => 'publish-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '448-2024-009668 LegalPublish',
        'summary' => 'City plans water and sewer service extension for the Bridger at Central Addition development.',
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('City plans water and sewer service extension');
});

it('treats publish note boilerplate titles as weak and replaces them from cleaned text', function () {
    $city = City::create([
        'name' => 'Notice City',
        'slug' => 'notice-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'GilMo Publish Note November 2023',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'Notice concerning Gilbert Mosley site certificate and release for environmental conditions.',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('Environmental conditions notice for Gilbert Mosley site certificate and release');
});

it('uses configured weak title patterns during refresh', function () {
    config()->set('articles.text_refresh.weak_title_patterns', [
        '/^custom boilerplate\b/i',
    ]);
    config()->set('articles.text_refresh.weak_title_source_patterns', []);

    $city = City::create([
        'name' => 'Config City',
        'slug' => 'config-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Custom Boilerplate Title',
        'summary' => 'City announces a new neighborhood sidewalk project.',
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('City announces a new neighborhood sidewalk project');
});

it('rewrites bid boilerplate into a concise document title', function () {
    $city = City::create([
        'name' => 'Bid City',
        'slug' => 'bid-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '472-2023-085936 LegalPublish',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'Bids for the Storm Water Drain #521 Improvements in Cottonwood Creek Estates will be accepted until 10:00 a.m. on October 4, 2024.',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('Bids sought for the Storm Water Drain #521 Improvements in Cottonwood Creek Estates');
});

it('rewrites environmental condition notices into concise document titles', function () {
    $city = City::create([
        'name' => 'Environmental City',
        'slug' => 'environmental-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '02 GilMo Publish',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'NOTICE CONCERNING GILBERT MOSLEY SITE CERTIFICATE AND RELEASE FOR ENVIRONMENTAL CONDITIONS',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('Environmental Conditions Notice For Gilbert Mosley Site Certificate And Release');
});

it('rewrites abbreviated service boilerplate into a readable title', function () {
    $city = City::create([
        'name' => 'Service City',
        'slug' => 'service-city',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => '448-2024-009668 LegalPublish',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'cleaned_text' => 'WDS t/w SS t/w SWD to serve Bridger at Central Addition',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $service = new ArticleTextService;
    $service->refresh($article->fresh());

    $article->refresh();

    expect($article->title)
        ->toBe('Water and sewer service for Bridger at Central Addition');
});
