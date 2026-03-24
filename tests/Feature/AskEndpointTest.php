<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\Event;
use App\Services\Chat\Agents\StructuredChatAnswerAgent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Carbon::setTestNow();
});

it('answers with citations from ingested sources', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Recycling & Trash',
        'source_url' => 'https://example.com/recycling',
        'tags' => ['trash'],
        'priority' => 10,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/recycling',
        'canonical_url' => 'https://example.com/recycling',
        'title' => 'Recycling & Trash',
        'content_text' => 'Trash pickup is on Monday.',
        'content_length' => 28,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Trash pickup is on Monday.',
        'content_length' => 28,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Trash pickup is on Monday.',
            'citations' => [
                [
                    'title' => 'Recycling & Trash',
                    'source_url' => 'https://example.com/recycling',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.9,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'When is trash pickup?',
        'city_id' => $city->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'Trash pickup is on Monday.')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/recycling');
});

it('returns the documented ask contract exactly', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Trash Service',
        'source_url' => 'https://example.com/trash',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/trash',
        'canonical_url' => 'https://example.com/trash',
        'title' => 'Trash Service',
        'content_text' => 'Trash pickup is on Monday.',
        'content_length' => 28,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Trash pickup is on Monday.',
        'content_length' => 28,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Trash pickup is on Monday.',
            'citations' => [
                [
                    'title' => 'Trash Service',
                    'source_url' => 'https://example.com/trash',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.95,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'When is trash pickup?',
        'city_id' => $city->id,
        'fallback_intent' => 'weekly_updates',
    ]);

    $response->assertOk();

    expect(array_keys($response->json()))->toBe(['answer', 'citations', 'city', 'meta'])
        ->and($response->json())->not->toHaveKey('resources');
});

it('returns deterministic fallback when evidence is insufficient', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Garage Sales Online',
        'source_url' => 'https://example.com/garage-sales',
        'tags' => ['permits'],
        'priority' => 10,
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'How do I get a permit?',
        'city_id' => $city->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'I could not find the answer in the sources I checked. Try a different wording or a more specific question.')
        ->assertJsonPath('citations', []);
});

it('uses seed evidence fallback when structured synthesis returns an empty answer', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.vector_enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Report a Water Leak',
        'source_url' => 'https://example.com/report-water-leak',
        'tags' => ['water', 'leak'],
        'priority' => 10,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/report-water-leak',
        'canonical_url' => 'https://example.com/report-water-leak',
        'title' => 'Report a Water Leak',
        'content_text' => 'To report a water leak, call 316-262-6000.',
        'content_length' => 44,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'To report a water leak, call 316-262-6000.',
        'content_length' => 44,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => '',
            'citations' => [],
            'source_mode' => 'none',
            'confidence' => 0.0,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'How do I report a water leak?',
        'city_id' => $city->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('citations.0.source_url', 'https://example.com/report-water-leak');

    expect((string) $response->json('answer'))
        ->toContain('To report a water leak, call 316-262-6000.')
        ->toContain('https://example.com/report-water-leak');
});

it('keeps event queries on the documented event-aware path', function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:00:00', 'America/Chicago'));

    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.events.enabled', true);
    config()->set('chat.events.intent_mode', 'intent');

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Events',
        'source_url' => 'https://example.com/events',
        'is_active' => true,
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Weekend Festival',
        'starts_at' => Carbon::parse('2026-02-14 10:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-14 18:00:00', 'America/Chicago'),
        'location_name' => 'Downtown',
        'event_url' => 'https://example.com/events/weekend-festival',
        'source_hash' => sha1('weekend-festival'),
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'I could not find the answer in the sources I checked.',
            'citations' => [],
            'source_mode' => 'none',
            'confidence' => 0.0,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => "What's going on this weekend?",
        'city_id' => $city->id,
    ]);

    $response->assertOk()
        ->assertSeeText('Weekend Festival')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/events/weekend-festival');
});
