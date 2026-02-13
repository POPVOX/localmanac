<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\Event;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\Agents\StructuredChatAnswerAgent;
use App\Services\Chat\Tools\EventSearchTool;
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

    $chunk = ChatSourceChunk::factory()->create([
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
            'confidence' => 0.82,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'When is trash pickup?',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'Trash pickup is on Monday.')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/recycling');
});

it('returns fallback answer when evidence is insufficient', function () {
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

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'How do I get a permit?',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertSeeText('I could not find the answer in the sources I checked.');
});

it('keeps reranked evidence when minimum score filtering is enabled', function () {
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
        'name' => 'Park Wichita',
        'source_url' => 'https://example.com/park-wichita',
        'tags' => ['parking'],
        'priority' => 10,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/park-wichita',
        'canonical_url' => 'https://example.com/park-wichita',
        'title' => 'Park Wichita | Wichita, KS',
        'content_text' => 'Fee schedule and paid parking hours.',
        'content_length' => 35,
    ]);

    $chunk = ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Fee schedule: paid parking is $1 per hour from 8 a.m. to 6 p.m. Monday through Thursday.',
        'content_length' => 95,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Paid parking is $1 per hour from 8 a.m. to 6 p.m. Monday through Thursday.',
            'citations' => [
                [
                    'title' => 'Park Wichita | Wichita, KS',
                    'source_url' => 'https://example.com/park-wichita',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.84,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'fee',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'Paid parking is $1 per hour from 8 a.m. to 6 p.m. Monday through Thursday.')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/park-wichita');
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

    StreamingChatAnswerAgent::fake([
        'To report a water leak, call 316-262-6000.',
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'How do I report a water leak?',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'To report a water leak, call 316-262-6000.')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/report-water-leak');
});

it('uses seed evidence fallback when structured synthesis returns a no-answer message', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.vector_enabled', false);
    config()->set('chat.chunk_max_chars', 1200);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Garage Sales Online',
        'source_url' => 'https://example.com/garage-sale-permit',
        'tags' => ['garage', 'permit'],
        'priority' => 10,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/garage-sale-permit',
        'canonical_url' => 'https://example.com/garage-sale-permit',
        'title' => 'Garage Sales Online',
        'content_text' => 'Garage sale permit pricing.',
        'content_length' => 27,
    ]);

    $leadingContext = str_repeat('Garage sale permit details and process. ', 24);
    $feeText = 'The permit is available for only $2.50 per day with a $1 credit card transaction fee.';

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => $leadingContext.$feeText,
        'content_length' => mb_strlen($leadingContext.$feeText),
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'I could not find the answer in the sources I checked.',
            'citations' => [],
            'source_mode' => 'none',
            'confidence' => 0.0,
        ],
    ]);

    StreamingChatAnswerAgent::fake([
        'The garage sale permit is $2.50 per day, plus a $1 credit card transaction fee.',
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'How much does a garage sale permit cost?',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'The garage sale permit is $2.50 per day, plus a $1 credit card transaction fee.')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/garage-sale-permit');
});

it('answers event asks and keeps ask response contract unchanged', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.web_search.enabled', false);

    Carbon::setTestNow(Carbon::parse('2026-02-12 10:00:00', 'America/Chicago'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Services',
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Riverside Arts Fest',
        'starts_at' => Carbon::parse('2026-02-14 11:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-14 17:00:00', 'America/Chicago'),
        'event_url' => 'https://events.wichita.gov/riverside-arts-fest',
        'source_hash' => sha1('riverside-arts-fest'),
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'This weekend in Wichita, Riverside Arts Fest runs on Saturday from 11:00 AM to 5:00 PM.',
            'citations' => [
                [
                    'title' => 'Riverside Arts Fest',
                    'source_url' => 'https://events.wichita.gov/riverside-arts-fest',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.88,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => "What's going on this weekend in the city?",
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'This weekend in Wichita, Riverside Arts Fest runs on Saturday from 11:00 AM to 5:00 PM.')
        ->assertJsonPath('citations.0.source_url', 'https://events.wichita.gov/riverside-arts-fest')
        ->assertJsonStructure([
            'answer',
            'citations',
            'city' => ['id', 'name', 'slug'],
            'meta' => ['sources_used', 'pages_fetched', 'cache_hits'],
        ]);

    StructuredChatAnswerAgent::assertPrompted(function ($prompt): bool {
        $tools = collect($prompt->agent->tools);

        return $prompt->contains('Event intent detected: yes')
            && $tools->contains(fn ($tool): bool => $tool instanceof EventSearchTool);
    });
});

it('uses current year for month-day event asks', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.events.web_fallback.enabled', false);

    Carbon::setTestNow(Carbon::parse('2026-02-12 10:00:00', 'America/Chicago'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Services',
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'I could not find the answer in the sources I checked.',
            'citations' => [],
            'source_mode' => 'none',
            'confidence' => 0.0,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'March 12th events',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertSeeText('I could not find any events in Wichita for March 12, 2026.');
});

it('returns combined answers for mixed civic and event asks', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.web_search.enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Services',
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'This weekend there is a downtown jazz show, and parking meters cost $1 per hour downtown.',
            'citations' => [
                [
                    'title' => 'Downtown Events',
                    'source_url' => 'https://events.wichita.gov/jazz',
                    'type' => 'html',
                ],
                [
                    'title' => 'Parking Rates',
                    'source_url' => 'https://www.wichita.gov/parking',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.86,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => "What's going on this weekend, and what is downtown parking?",
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertSeeText('downtown jazz show')
        ->assertSeeText('$1 per hour');
});

it('returns explicit no-events guidance when no local events are found', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.events.no_results_suggest_alternatives', true);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Services',
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'I could not find the answer in the sources I checked.',
            'citations' => [],
            'source_mode' => 'none',
            'confidence' => 0.0,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => "What's going on this weekend?",
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertSeeText('I could not find any events in Wichita for this weekend.')
        ->assertSeeText('Try asking about the next 7 days or next weekend.');
});

it('falls back to deterministic local event summary when llm returns no-answer but events exist', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.events.web_fallback.enabled', false);

    Carbon::setTestNow(Carbon::parse('2026-02-12 10:00:00', 'America/Chicago'));

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Services',
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Neighborhood Night Market',
        'starts_at' => Carbon::parse('2026-02-12 18:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-12 21:00:00', 'America/Chicago'),
        'location_name' => 'Downtown',
        'event_url' => 'https://events.wichita.gov/night-market',
        'source_hash' => sha1('night-market'),
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Weekend Family Concert',
        'starts_at' => Carbon::parse('2026-02-14 14:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-14 16:00:00', 'America/Chicago'),
        'location_name' => 'Riverfront Stadium',
        'event_url' => 'https://events.wichita.gov/family-concert',
        'source_hash' => sha1('family-concert'),
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'I could not find the answer in the sources I checked.',
            'citations' => [],
            'source_mode' => 'none',
            'confidence' => 0.0,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'What events or activities are going on this week?',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertSeeText('Top events in Wichita for this week')
        ->assertJsonPath('citations.0.source_url', 'https://events.wichita.gov/night-market');

    expect((string) $response->json('answer'))->not->toContain('I could not find any events in Wichita');
});

it('caps response citations to link limit', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.link_limit', 3);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Services',
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Here are the latest service updates.',
            'citations' => [
                ['title' => 'One', 'source_url' => 'https://example.com/1', 'type' => 'html'],
                ['title' => 'Two', 'source_url' => 'https://example.com/2', 'type' => 'html'],
                ['title' => 'Three', 'source_url' => 'https://example.com/3', 'type' => 'html'],
                ['title' => 'Four', 'source_url' => 'https://example.com/4', 'type' => 'html'],
                ['title' => 'Five', 'source_url' => 'https://example.com/5', 'type' => 'html'],
            ],
            'source_mode' => 'local',
            'confidence' => 0.85,
        ],
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'What are this week\'s city updates?',
            'city_id' => $city->id,
        ]);

    $response->assertOk();

    expect($response->json('citations'))->toHaveCount(3);
});
