<?php

use App\Models\Article;
use App\Models\City;
use App\Models\Claim;
use App\Models\IssueArea;
use App\Services\Extraction\ClaimWriter;
use App\Support\Claims\ClaimTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('writes and replaces llm claims for an article', function () {
    $city = City::create([
        'name' => 'Claim City',
        'slug' => 'claim-city',
    ]);

    $issueArea = IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Housing',
        'slug' => 'housing',
    ]);

    $article = Article::create([
        'city_id' => $city->id,
        'title' => 'Claim Article',
        'status' => 'published',
        'content_type' => 'html',
    ]);

    $payload = [
        'people' => [
            [
                'name' => 'Jane Doe',
                'role' => 'Mayor',
                'confidence' => 0.8,
                'evidence' => [
                    ['quote' => 'Mayor Jane Doe said the council would vote.'],
                ],
            ],
        ],
        'organizations' => [
            [
                'name' => 'City Council',
                'type_guess' => 'government',
                'confidence' => 0.9,
                'evidence' => [
                    ['quote' => 'The City Council met Tuesday night.'],
                ],
            ],
        ],
        'locations' => [
            [
                'name' => 'City Hall',
                'address' => '123 Main St',
                'confidence' => 0.7,
                'evidence' => [
                    ['quote' => 'The meeting was held at City Hall.'],
                ],
            ],
        ],
        'keywords' => [
            [
                'keyword' => 'zoning',
                'confidence' => 0.6,
                'evidence' => [
                    ['quote' => 'Zoning changes were discussed.'],
                ],
            ],
        ],
        'issue_areas' => [
            [
                'slug' => $issueArea->slug,
                'confidence' => 0.75,
                'evidence' => [
                    ['quote' => 'Affordable housing remains a priority.'],
                ],
            ],
        ],
        'confidence' => 0.83,
    ];

    $writer = new ClaimWriter;
    $writer->write($article, $payload, 'test-model', 'test-prompt');

    expect(Claim::count())->toBe(5);

    $personClaim = Claim::query()
        ->where('claim_type', ClaimTypes::ARTICLE_MENTIONS_PERSON)
        ->first();

    expect($personClaim)->not->toBeNull()
        ->and($personClaim?->value_json['name'])->toBe('Jane Doe')
        ->and($personClaim?->value_hash)->not->toBeNull();

    $writer->write($article, $payload, 'test-model', 'test-prompt');

    expect(Claim::count())->toBe(5);
});
