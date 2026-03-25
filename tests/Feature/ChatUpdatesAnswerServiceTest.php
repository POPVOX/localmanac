<?php

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Models\ArticleSource;
use App\Models\City;
use App\Services\Chat\ChatUpdatesAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('summarizes recent article-backed updates for updates-mode queries', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-24 15:00:00', 'UTC'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $recentArticles = [
        [
            'title' => 'Water Service Alert Update',
            'summary' => 'Water crews posted a new service alert for east-side repairs.',
            'published_at' => '2026-03-24 13:00:00',
            'url' => 'https://example.com/updates/service-alert-march-24',
            'explainer' => 'Water service work will affect several east-side blocks on Tuesday.',
        ],
        [
            'title' => 'Rezoning Filing Update',
            'summary' => 'A new rezoning filing was submitted near Central and Oliver.',
            'published_at' => '2026-03-23 11:00:00',
            'url' => 'https://example.com/updates/rezoning-march-23',
            'explainer' => 'The filing would rezone property near Central and Oliver for mixed-use development.',
        ],
        [
            'title' => 'Downtown Project Approval',
            'summary' => 'The city approved a downtown redevelopment project this week.',
            'published_at' => '2026-03-21 09:00:00',
            'url' => 'https://example.com/updates/project-march-21',
            'explainer' => 'City approval cleared a downtown site for the next phase of redevelopment.',
        ],
    ];

    foreach ($recentArticles as $articleData) {
        $article = Article::factory()->create([
            'city_id' => $city->id,
            'title' => $articleData['title'],
            'summary' => $articleData['summary'],
            'published_at' => Carbon::parse($articleData['published_at'], 'UTC'),
            'canonical_url' => $articleData['url'],
        ]);

        ArticleBody::factory()->create([
            'article_id' => $article->id,
            'cleaned_text' => $articleData['explainer'],
        ]);

        ArticleExplainer::create([
            'article_id' => $article->id,
            'city_id' => $city->id,
            'whats_happening' => $articleData['explainer'],
            'why_it_matters' => null,
            'key_details' => [],
            'what_to_watch' => [],
            'evidence_json' => [],
            'source' => 'test',
            'source_payload' => [],
        ]);

        ArticleAnalysis::factory()->create([
            'article_id' => $article->id,
            'coverage_scope' => 'local',
            'local_relevance_score' => 0.9,
            'civic_relevance_score' => 0.9,
            'final_scores' => [
                'agency' => 0.8,
                'timeliness' => 0.9,
            ],
        ]);

        ArticleSource::create([
            'city_id' => $city->id,
            'article_id' => $article->id,
            'organization_id' => null,
            'source_url' => $articleData['url'],
            'source_type' => 'html',
            'source_uid' => null,
            'accessed_at' => Carbon::parse($articleData['published_at'], 'UTC'),
        ]);
    }

    $olderArticle = Article::factory()->create([
        'city_id' => $city->id,
        'title' => 'January Evergreen Project Page',
        'summary' => 'Long-running project overview.',
        'published_at' => Carbon::parse('2026-01-10 09:00:00', 'UTC'),
        'canonical_url' => 'https://example.com/updates/evergreen-project-page',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $olderArticle->id,
        'cleaned_text' => 'General evergreen information about a long-running project.',
    ]);

    $result = app(ChatUpdatesAnswerService::class)->answer(
        'Summarize the most important local updates in Wichita from the last 7 days.',
        $city,
    );

    expect($result['answer'])
        ->toContain('Here are the most important local updates I found from the last 7 days:')
        ->toContain('Water Service Alert Update')
        ->toContain('Rezoning Filing Update')
        ->toContain('Downtown Project Approval')
        ->not->toContain('January Evergreen Project Page')
        ->and(collect($result['citations'])->pluck('source_url')->all())->toBe([
            'https://example.com/updates/service-alert-march-24',
            'https://example.com/updates/rezoning-march-23',
            'https://example.com/updates/project-march-21',
        ])
        ->and($result['meta']['sources_used'])->toBe(3);

    Carbon::setTestNow();
});

it('returns a clean updates fallback for service-alert queries without using procedural fallback text', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-24 15:00:00', 'UTC'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
        'title' => 'Rezoning Filing Update',
        'summary' => 'A rezoning filing was submitted near Central and Oliver.',
        'published_at' => Carbon::parse('2026-03-23 11:00:00', 'UTC'),
        'canonical_url' => 'https://example.com/updates/rezoning-march-23',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => 'The filing would rezone property near Central and Oliver for mixed-use development.',
    ]);

    $result = app(ChatUpdatesAnswerService::class)->answer(
        'What active service alerts or disruptions should residents in Wichita know about right now? Focus on roads, utilities, water, trash, and public services.',
        $city,
    );

    expect($result['answer'])->toBe('I could not find active local service alerts or disruptions in the available article sources right now.')
        ->and($result['answer'])->not->toContain('permit or formal review may be required')
        ->and($result['citations'])->toBe([])
        ->and($result['meta']['sources_used'])->toBe(0);

    Carbon::setTestNow();
});

it('formats updates-mode items as clean readable sentences without truncated fragments', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-24 15:00:00', 'UTC'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
        'title' => 'Water Main Repair Update (',
        'summary' => 'Crews closed Main Street near City Hall (',
        'published_at' => Carbon::parse('2026-03-24 12:00:00', 'UTC'),
        'canonical_url' => 'https://example.com/updates/water-main-repair',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => 'Crews closed Main Street near City Hall for water-line repairs. Drivers should use Douglas or Market until the work is complete.',
    ]);

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'whats_happening' => 'Crews closed Main Street near City Hall for water-line repairs. Drivers should use Douglas or Market until the work is complete.',
        'why_it_matters' => 'This means drivers should use Douglas or Market until the work is complete.',
        'key_details' => [],
        'what_to_watch' => [],
        'evidence_json' => [],
        'source' => 'test',
        'source_payload' => [],
    ]);

    ArticleAnalysis::factory()->create([
        'article_id' => $article->id,
        'coverage_scope' => 'local',
        'local_relevance_score' => 0.9,
        'civic_relevance_score' => 0.9,
        'final_scores' => [
            'agency' => 0.8,
            'timeliness' => 0.9,
        ],
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'organization_id' => null,
        'source_url' => 'https://example.com/updates/water-main-repair',
        'source_type' => 'html',
        'source_uid' => null,
        'accessed_at' => Carbon::parse('2026-03-24 12:00:00', 'UTC'),
    ]);

    $result = app(ChatUpdatesAnswerService::class)->answer(
        'What is new this week in Wichita?',
        $city,
    );

    expect($result['answer'])
        ->toContain('Here are the most important local updates I found for this week:')
        ->toContain('- Mar 24: Water Main Repair Update, crews closed Main Street near City Hall for water-line repairs, which means drivers should use Douglas or Market until the work is complete.')
        ->not->toContain('...')
        ->not->toContain('(,')
        ->not->toContain('Update (')
        ->not->toContain('City Hall (');

    Carbon::setTestNow();
});

it('only selects updates inside the requested relative window before summarization', function (string $query, array $includedTitles, array $excludedTitles, int $expectedSourcesUsed) {
    Carbon::setTestNow(Carbon::parse('2026-03-24 15:00:00', 'UTC'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $articles = [
        [
            'title' => 'Today Update',
            'summary' => 'A city update was posted today.',
            'published_at' => '2026-03-24 10:00:00',
            'url' => 'https://example.com/updates/today',
        ],
        [
            'title' => 'Two Days Ago Update',
            'summary' => 'An update was posted two days ago.',
            'published_at' => '2026-03-22 11:00:00',
            'url' => 'https://example.com/updates/two-days-ago',
        ],
        [
            'title' => 'Six Days Ago Update',
            'summary' => 'An update was posted six days ago.',
            'published_at' => '2026-03-18 12:00:00',
            'url' => 'https://example.com/updates/six-days-ago',
        ],
        [
            'title' => 'Twelve Days Ago Update',
            'summary' => 'An update was posted twelve days ago.',
            'published_at' => '2026-03-12 09:00:00',
            'url' => 'https://example.com/updates/twelve-days-ago',
        ],
        [
            'title' => 'Sixteen Days Ago Update',
            'summary' => 'An update was posted sixteen days ago.',
            'published_at' => '2026-03-08 09:00:00',
            'url' => 'https://example.com/updates/sixteen-days-ago',
        ],
    ];

    foreach ($articles as $articleData) {
        $article = Article::factory()->create([
            'city_id' => $city->id,
            'title' => $articleData['title'],
            'summary' => $articleData['summary'],
            'published_at' => Carbon::parse($articleData['published_at'], 'UTC'),
            'canonical_url' => $articleData['url'],
        ]);

        ArticleBody::factory()->create([
            'article_id' => $article->id,
            'cleaned_text' => $articleData['summary'],
        ]);

        ArticleAnalysis::factory()->create([
            'article_id' => $article->id,
            'coverage_scope' => 'local',
            'local_relevance_score' => 0.9,
            'civic_relevance_score' => 0.9,
            'final_scores' => [
                'agency' => 0.8,
                'timeliness' => 0.9,
            ],
        ]);

        ArticleSource::create([
            'city_id' => $city->id,
            'article_id' => $article->id,
            'organization_id' => null,
            'source_url' => $articleData['url'],
            'source_type' => 'html',
            'source_uid' => null,
            'accessed_at' => Carbon::parse($articleData['published_at'], 'UTC'),
        ]);
    }

    $result = app(ChatUpdatesAnswerService::class)->answer($query, $city);

    foreach ($includedTitles as $title) {
        expect($result['answer'])->toContain($title);
    }

    foreach ($excludedTitles as $title) {
        expect($result['answer'])->not->toContain($title);
    }

    $citationTitles = collect($result['citations'])->pluck('title')->all();

    expect($result['meta']['sources_used'])->toBe($expectedSourcesUsed);

    foreach ($excludedTitles as $title) {
        expect($citationTitles)->not->toContain($title);
    }

    Carbon::setTestNow();
})->with([
    'last 5 days' => [
        'query' => 'Summarize local updates from the last 5 days.',
        'includedTitles' => ['Today Update', 'Two Days Ago Update'],
        'excludedTitles' => ['Six Days Ago Update', 'Twelve Days Ago Update', 'Sixteen Days Ago Update'],
        'expectedSourcesUsed' => 2,
    ],
    'last 7 days' => [
        'query' => 'Summarize local updates from the last 7 days.',
        'includedTitles' => ['Today Update', 'Two Days Ago Update', 'Six Days Ago Update'],
        'excludedTitles' => ['Twelve Days Ago Update', 'Sixteen Days Ago Update'],
        'expectedSourcesUsed' => 3,
    ],
    'last 14 days' => [
        'query' => 'Summarize local updates from the last 14 days.',
        'includedTitles' => ['Today Update', 'Two Days Ago Update', 'Six Days Ago Update'],
        'excludedTitles' => ['Sixteen Days Ago Update'],
        'expectedSourcesUsed' => 3,
    ],
]);

it('returns the normal fallback when no updates fall inside the resolved relative window', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-24 15:00:00', 'UTC'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
        'title' => 'Six Days Ago Update',
        'summary' => 'An update was posted six days ago.',
        'published_at' => Carbon::parse('2026-03-18 12:00:00', 'UTC'),
        'canonical_url' => 'https://example.com/updates/six-days-ago',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => 'An update was posted six days ago.',
    ]);

    ArticleAnalysis::factory()->create([
        'article_id' => $article->id,
        'coverage_scope' => 'local',
        'local_relevance_score' => 0.9,
        'civic_relevance_score' => 0.9,
        'final_scores' => [
            'agency' => 0.8,
            'timeliness' => 0.9,
        ],
    ]);

    $result = app(ChatUpdatesAnswerService::class)->answer(
        'Summarize local updates from the last 5 days.',
        $city,
    );

    expect($result['answer'])->toBe('I could not find enough recent local updates in the available article sources from the last 5 days.')
        ->and($result['citations'])->toBe([])
        ->and($result['meta']['sources_used'])->toBe(0);

    Carbon::setTestNow();
});
