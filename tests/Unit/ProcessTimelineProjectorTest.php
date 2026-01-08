<?php

use App\Models\Article;
use App\Models\City;
use App\Models\ProcessTimelineItem;
use App\Services\Analysis\ProcessTimelineProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('projects process timeline items from payload', function () {
    Carbon::setTestNow(Carbon::parse('2025-01-10 12:00:00', 'America/Chicago'));

    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
    ]);

    $payload = [
        'process_timeline' => [
            'items' => [
                [
                    'key' => 'proposal_submitted',
                    'label' => 'Proposal Submitted',
                    'date' => '2025-01-01',
                    'status' => 'completed',
                    'badge_text' => null,
                    'note' => null,
                    'evidence' => [
                        ['quote' => 'Proposal submitted on Jan 1.'],
                    ],
                ],
                [
                    'key' => 'public_comment_period',
                    'label' => 'Public Comment Period',
                    'date' => '2025-01-10',
                    'ends_at' => '2025-01-20',
                    'status' => 'current',
                    'badge_text' => 'OPEN NOW',
                    'note' => null,
                    'evidence' => [
                        ['quote' => 'Public comment is open.'],
                    ],
                ],
                [
                    'key' => 'hearing',
                    'label' => 'Planning Commission Hearing',
                    'date' => '2025-01-20',
                    'status' => 'upcoming',
                    'badge_text' => null,
                    'note' => 'Planning Commission',
                    'evidence' => [
                        ['quote' => 'Hearing on Jan 20.'],
                    ],
                ],
                [
                    'key' => 'hearing',
                    'label' => 'Hearing (duplicate)',
                    'date' => '2025-01-21',
                    'status' => 'upcoming',
                    'badge_text' => null,
                    'note' => null,
                    'evidence' => [
                        ['quote' => 'Another hearing mention.'],
                    ],
                ],
            ],
            'current_key' => 'public_comment_period',
        ],
    ];

    app(ProcessTimelineProjector::class)->projectForArticle($article, $payload);

    $items = ProcessTimelineItem::query()
        ->where('article_id', $article->id)
        ->orderBy('position')
        ->get();

    expect($items)->toHaveCount(3)
        ->and($items->pluck('key')->all())->toBe([
            'proposal_submitted',
            'public_comment_period',
            'hearing',
        ]);

    $comment = $items->firstWhere('key', 'public_comment_period');

    expect($comment)->not->toBeNull()
        ->and($comment?->badge_text)->toBe('OPEN NOW')
        ->and($comment?->status)->toBe('current');

    $submitted = $items->firstWhere('key', 'proposal_submitted');
    $submittedLocalDate = $submitted?->date?->clone()->timezone('America/Chicago')->format('Y-m-d');

    expect($submittedLocalDate)->toBe('2025-01-01')
        ->and($submitted?->has_time)->toBeFalse();

    Carbon::setTestNow();
});

it('clears open-now badge for past dated items', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-07 12:00:00', 'America/Chicago'));

    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
    ]);

    $payload = [
        'process_timeline' => [
            'items' => [
                [
                    'key' => 'public_hearing',
                    'label' => 'Public Hearing',
                    'date' => '2025-10-21',
                    'status' => 'current',
                    'badge_text' => 'OPEN NOW',
                    'note' => null,
                    'evidence' => [
                        ['quote' => 'The public hearing was held on Oct. 21, 2025.'],
                    ],
                ],
            ],
            'current_key' => null,
        ],
    ];

    app(ProcessTimelineProjector::class)->projectForArticle($article, $payload);

    $item = ProcessTimelineItem::query()
        ->where('article_id', $article->id)
        ->first();

    expect($item)->not->toBeNull()
        ->and($item?->status)->toBe('completed')
        ->and($item?->badge_text)->toBeNull();

    Carbon::setTestNow();
});
