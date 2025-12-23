<?php

use App\Models\Article;
use App\Models\ArticleEntity;
use App\Models\ArticleIssueArea;
use App\Models\ArticleKeyword;
use App\Models\City;
use App\Models\Claim;
use App\Models\IssueArea;
use App\Models\Keyword;
use App\Services\Extraction\ProjectionWriter;
use App\Support\Claims\ClaimTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('projects claims into keyword, entity, and issue area tables', function () {
    config()->set('enrichment.projections.min_confidence', 0.6);

    $city = City::create([
        'name' => 'Projection City',
        'slug' => 'projection-city',
    ]);

    $issueArea = IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Transportation',
        'slug' => 'transportation',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Projection Article',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    Claim::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'claim_type' => ClaimTypes::ARTICLE_KEYWORD,
        'value_json' => ['keyword' => 'public transit'],
        'confidence' => 0.8,
        'source' => 'llm',
        'status' => 'proposed',
        'value_hash' => 'kw-1',
    ]);

    Claim::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'claim_type' => ClaimTypes::ARTICLE_KEYWORD,
        'value_json' => ['keyword' => 'public transit'],
        'confidence' => 0.65,
        'source' => 'llm',
        'status' => 'proposed',
        'value_hash' => 'kw-2',
    ]);

    Claim::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'claim_type' => ClaimTypes::ARTICLE_KEYWORD,
        'value_json' => ['keyword' => 'ignored low confidence'],
        'confidence' => 0.2,
        'source' => 'llm',
        'status' => 'proposed',
        'value_hash' => 'kw-3',
    ]);

    Claim::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'claim_type' => ClaimTypes::ARTICLE_MENTIONS_PERSON,
        'value_json' => ['name' => 'Alex Smith'],
        'confidence' => 0.9,
        'source' => 'llm',
        'status' => 'proposed',
        'value_hash' => 'person-1',
    ]);

    Claim::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'claim_type' => ClaimTypes::ARTICLE_ISSUE_AREA,
        'value_json' => ['slug' => $issueArea->slug],
        'confidence' => 0.7,
        'source' => 'llm',
        'status' => 'proposed',
        'value_hash' => 'issue-1',
    ]);

    $writer = new ProjectionWriter;
    $writer->write($article);

    expect(Keyword::count())->toBe(1)
        ->and(ArticleKeyword::count())->toBe(1)
        ->and(ArticleEntity::count())->toBe(1)
        ->and(ArticleIssueArea::count())->toBe(1);

    $keyword = Keyword::first();
    $articleKeyword = ArticleKeyword::first();

    expect($keyword)->not->toBeNull()
        ->and($articleKeyword?->confidence)->toBe(0.8);
});
