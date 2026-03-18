<?php

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Models\City;

it('backfills weak meeting explainers for existing articles', function () {
    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'March 10 City Council Meeting recap',
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

    $this->artisan('articles:backfill-meeting-summaries --limit=1')
        ->expectsOutputToContain('Backfilled 1 of 1 article(s).')
        ->assertExitCode(0);

    $article->refresh();
    $explainer = $article->explainer()->first();

    expect($explainer)->not->toBeNull()
        ->and($explainer?->whats_happening)->toContain('Consent Agenda with the exception of item 6')
        ->and($explainer?->whats_happening)->toContain('Board of Bids and Contracts')
        ->and($explainer?->why_it_matters)->toBeNull();

    expect($article->fresh()?->summary)
        ->toContain('Consent Agenda with the exception of item 6')
        ->toContain('Board of Bids and Contracts');
});

it('can scope the backfill by city slug', function () {
    $wichita = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $topeka = City::create([
        'name' => 'Topeka',
        'slug' => 'topeka',
    ]);

    $wichitaArticle = Article::create([
        'city_id' => $wichita->id,
        'title' => 'District 2 Advisory Board — Notes',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $wichitaArticle->id,
        'cleaned_text' => implode("\n\n", [
            'Katie Eddy from the Parks and Recreation department presented on the Imagine ICT! Master Plan process.',
            'There is an additional online survey available that closes this Friday where they hope to have 150 more completed.',
            'Also online is an interactive map where you can drop and pin and leave a comment.',
            'There is a survey available online for the board and members of the public to submit their thoughts on economic mobility in Wichita.',
        ]),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $topekaArticle = Article::create([
        'city_id' => $topeka->id,
        'title' => 'City Council Meeting recap',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    ArticleBody::create([
        'article_id' => $topekaArticle->id,
        'cleaned_text' => implode("\n\n", [
            'Yesterday at the City Council meeting, the Council heard the following items:',
            '* Consent Agenda – Approved 7/0',
            '* Board of Bids and Contracts – Approved 7/0',
        ]),
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $this->artisan('articles:backfill-meeting-summaries --city=wichita')
        ->expectsOutputToContain('Backfilled 1 of 1 article(s).')
        ->assertExitCode(0);

    expect($wichitaArticle->explainer()->first()?->whats_happening)
        ->toContain('Imagine ICT! Master Plan process')
        ->and($wichitaArticle->fresh()?->summary)->toContain('Imagine ICT! Master Plan process')
        ->and($topekaArticle->explainer()->first())->toBeNull();
});
