<?php

use App\Models\Article;
use App\Models\ChatSource;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('answers local news updates without requiring a separate chat source', function () {
    $city = City::factory()->create(['name' => 'Jackson', 'slug' => 'jackson-tn']);
    $otherCity = City::factory()->create();
    $article = Article::factory()->create([
        'city_id' => $city->id,
        'title' => 'Downtown water main repairs begin',
        'summary' => 'Water crews will repair the Main Street water line this week.',
        'canonical_url' => 'https://example.gov/jackson/water-repairs',
        'published_at' => now()->subHour(),
    ]);
    Article::factory()->create([
        'city_id' => $otherCity->id,
        'title' => 'Other city bridge closure',
        'published_at' => now()->subHour(),
    ]);
    StreamingChatAnswerAgent::fake();

    $result = app(AskService::class)->answerStreamingForUser(
        'What is new in Jackson this week?', $city->id, User::factory()->create(), null, fn () => null,
    );

    expect(ChatSource::count())->toBe(0)
        ->and($result['answer'])->toContain($article->title)->not->toContain('Other city bridge closure')
        ->and(collect($result['citations'])->pluck('source_url')->all())->toContain($article->canonical_url);
    StreamingChatAnswerAgent::assertNeverPrompted();
});

it('answers city calendar questions without requiring a separate chat source', function () {
    $city = City::factory()->create(['name' => 'Jackson', 'slug' => 'jackson-tn', 'timezone' => 'America/Chicago']);
    $event = Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Jackson City Council Meeting',
        'description' => 'City council public meeting with agenda.',
        'starts_at' => now()->addDays(2),
        'event_url' => 'https://example.gov/jackson/council-meeting',
    ]);
    StreamingChatAnswerAgent::fake();

    $result = app(AskService::class)->answerStreamingForUser(
        'What city council meetings are coming up in Jackson in the next 14 days?', $city->id, User::factory()->create(), null, fn () => null,
    );

    expect(ChatSource::count())->toBe(0)
        ->and($result['answer'])->toContain($event->title)
        ->and(collect($result['citations'])->pluck('source_url')->all())->toContain($event->event_url);
    StreamingChatAnswerAgent::assertNeverPrompted();
});

it('keeps an empty city empty instead of using another city news', function () {
    $city = City::factory()->create(['name' => 'Jackson', 'slug' => 'jackson-tn']);
    Article::factory()->create(['title' => 'Other city bridge closure', 'published_at' => now()->subHour()]);
    StreamingChatAnswerAgent::fake();

    $result = app(AskService::class)->answerStreamingForUser(
        'What is new in Jackson this week?', $city->id, User::factory()->create(), null, fn () => null,
    );

    expect($result['answer'])->not->toContain('Other city bridge closure')
        ->and($result['citations'])->toBe([]);
    StreamingChatAnswerAgent::assertNeverPrompted();
});

it('cites the calendar events named in a model answer instead of unrelated news', function () {
    config(['chat.vector_enabled' => false, 'chat.tools.web_search.enabled' => false, 'chat.tools.similarity.enabled' => false, 'chat.answer_quality_enabled' => false]);
    $city = City::factory()->create(['name' => 'Jackson', 'slug' => 'jackson-tn', 'timezone' => 'America/Chicago']);
    $event = Event::factory()->create([
        'city_id' => $city->id, 'title' => 'Jackson Symphony',
        'description' => 'An evening of classical music.', 'starts_at' => now()->addDays(2),
        'event_url' => 'https://example.gov/jackson/symphony',
    ]);
    Event::factory()->create(['city_id' => $city->id, 'title' => 'Other Local Festival', 'starts_at' => now()->addDays(3), 'event_url' => 'https://example.gov/jackson/other']);
    Article::factory()->create(['city_id' => $city->id, 'title' => 'Jackson festival last month', 'summary' => 'News about past Jackson events.', 'canonical_url' => 'https://example.gov/jackson/old-festival']);
    StreamingChatAnswerAgent::fake(['Enjoy Jackson Symphony, an evening of classical music.']);
    $result = app(AskService::class)->answerStreamingForUser('What events are coming up in Jackson in the next 14 days?', $city->id, User::factory()->create(), null, fn () => null);
    expect($result['answer'])->toContain('Jackson Symphony')
        ->and(collect($result['citations'])->pluck('source_url')->all())->toBe([$event->event_url]);
});
