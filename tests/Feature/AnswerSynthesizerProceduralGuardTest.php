<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not classify event-style meeting questions as procedural questions', function () {
    $retriever = app(\App\Services\Chat\ChatSourceRetriever::class);
    $method = new ReflectionMethod(\App\Services\Chat\ChatSourceRetriever::class, 'isProceduralQuestion');
    $method->setAccessible(true);

    expect($method->invoke(
        $retriever,
        'What city council, board, and public meetings are coming up in Wichita in the next 14 days? Include dates, times, and where to find the agenda.'
    ))->toBeFalse()
        ->and($method->invoke($retriever, 'How do I get a demolition permit?'))->toBeTrue();
});

it('does not trigger procedural constraining since it has been removed from AnswerSynthesizer', function () {
    expect(method_exists(AnswerSynthesizer::class, 'shouldConstrainProceduralAnswer'))->toBeFalse()
        ->and(method_exists(AnswerSynthesizer::class, 'narrowProceduralAnswerFromEvidence'))->toBeFalse()
        ->and(method_exists(AnswerSynthesizer::class, 'shouldRejectProceduralEventAnswer'))->toBeFalse()
        ->and(method_exists(AnswerSynthesizer::class, 'shouldRejectProceduralAnswerForNonProceduralQuery'))->toBeFalse();
});

it('passes LLM answer through single synthesis path without procedural constraining', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita Government',
        'source_url' => 'https://www.wichita.gov/27/Government',
        'priority' => 10,
        'is_active' => true,
    ]);

    $definitionPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/DocumentCenter/View/12635',
        'canonical_url' => 'https://www.wichita.gov/DocumentCenter/View/12635',
        'title' => 'Building Code Definition',
        'content_text' => 'Demolition means activity that requires a demolition permit under the building code.',
        'content_length' => 79,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $definitionPage->id,
        'chunk_index' => 0,
        'content' => 'Demolition means activity that requires a demolition permit under the building code.',
        'content_length' => 79,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    $historicPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/DocumentCenter/View/12636',
        'canonical_url' => 'https://www.wichita.gov/DocumentCenter/View/12636',
        'title' => 'Historic Resources Demolition Review',
        'content_text' => 'c) The Office of Central Inspection is prohibited from issuing any permit during interim control. If the project involves demolition of a historic resource or property in a historic district, submit a Certificate of Appropriateness for review before work begins.',
        'content_length' => 160,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $historicPage->id,
        'chunk_index' => 0,
        'content' => 'c) The Office of Central Inspection is prohibited from issuing any permit during interim control. If the project involves demolition of a historic resource or property in a historic district, submit a Certificate of Appropriateness for review before work begins.',
        'content_length' => 258,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    $inspectionPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/DocumentCenter/View/12637',
        'canonical_url' => 'https://www.wichita.gov/DocumentCenter/View/12637',
        'title' => 'Demolition Completion Requirements',
        'content_text' => 'Final inspection is made after demolition debris, foundations, and utilities are removed from the property and disposed of properly.',
        'content_length' => 126,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $inspectionPage->id,
        'chunk_index' => 0,
        'content' => 'Final inspection is made after demolition debris, foundations, and utilities are removed from the property and disposed of properly.',
        'content_length' => 126,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StreamingChatAnswerAgent::fake([
        "1. Submit the application to the central inspection office.\n2. Pay the fee and post a bond.\n3. Schedule the final inspection.",
    ]);

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: 'How do I get a demolition permit?',
        city: $city,
        sources: collect([$source]),
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
        originalQuestion: 'How do I get a demolition permit?',
    );

    $answer = $result['answer'];

    expect($answer)
        ->not->toContain('permit or formal review may be required')
        ->not->toContain('The full step-by-step process is not clearly described')
        ->and($result['citations'])->not->toBeEmpty();
});

it('keeps exact meeting queries on the event path instead of the procedural fallback summary', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::factory()->create();

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Wichita City Council Workshop',
        'starts_at' => now('America/Chicago')->addDays(5)->setTime(9, 0),
        'ends_at' => now('America/Chicago')->addDays(5)->setTime(11, 0),
        'all_day' => false,
        'location_name' => 'City Hall',
        'description' => 'City council workshop with agenda posted online.',
        'event_url' => 'https://www.wichita.gov/AgendaCenter/Wichita-City-Council-Meetings-34',
        'source_hash' => 'city-council-workshop',
    ]);

    StreamingChatAnswerAgent::fake([
        "1. Submit the application.\n2. Wait for permit review.\n3. Schedule the final inspection.",
    ]);

    $query = 'What city council, board, and public meetings are coming up in Wichita in the next 14 days? Include dates, times, and where to find the agenda.';

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: $query,
        city: $city,
        sources: collect(),
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
        originalQuestion: $query,
    );

    expect($result['answer'])
        ->toContain('Wichita City Council Workshop')
        ->not->toContain('permit or formal review may be required')
        ->not->toContain('Submit the application')
        ->not->toContain('final inspection')
        ->and($result['citations'])->not->toBeEmpty()
        ->and(collect($result['citations'])->pluck('source_url')->all())
        ->toContain('https://www.wichita.gov/AgendaCenter/Wichita-City-Council-Meetings-34');
});

it('returns a clean civic meetings fallback when only unrelated library events are available', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::factory()->create();

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Tuesday Topics: Understanding Immigration',
        'starts_at' => now('America/Chicago')->addDays(2)->setTime(18, 0),
        'ends_at' => now('America/Chicago')->addDays(2)->setTime(19, 0),
        'all_day' => false,
        'location_name' => 'Advanced Learning Library',
        'description' => 'Library program and community discussion.',
        'event_url' => 'https://wichitalibrary.libnet.info/event/15409343',
        'source_hash' => 'library-topic',
    ]);

    StreamingChatAnswerAgent::fake([
        'I could not find the answer in the sources I checked.',
    ]);

    $query = 'What city council, board, and public meetings are coming up in Wichita in the next 14 days? Include dates, times, and where to find the agenda.';

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: $query,
        city: $city,
        sources: collect(),
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
        originalQuestion: $query,
    );

    expect($result['answer'])->toBe('I could not find any upcoming city council or public meetings in the available sources.')
        ->and($result['citations'])->toBe([]);
});

it('does not force local event override for meeting queries when LLM provides a grounded answer', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::factory()->create();

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Wichita City Council Workshop',
        'starts_at' => now('America/Chicago')->addDays(5)->setTime(9, 0),
        'ends_at' => now('America/Chicago')->addDays(5)->setTime(11, 0),
        'all_day' => false,
        'location_name' => 'City Hall',
        'description' => 'City council workshop with agenda posted online.',
        'event_url' => 'https://www.wichita.gov/AgendaCenter/Wichita-City-Council-Meetings-34',
        'source_hash' => 'city-council-workshop-final-answer',
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Planning Commission Regular Meeting',
        'starts_at' => now('America/Chicago')->addDays(7)->setTime(9, 0),
        'ends_at' => now('America/Chicago')->addDays(7)->setTime(11, 0),
        'all_day' => false,
        'location_name' => 'City Hall',
        'description' => 'Planning commission meeting with agenda posted online.',
        'event_url' => 'https://www.wichita.gov/AgendaCenter/planning-commission',
        'source_hash' => 'planning-commission-final-answer',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Weekly Entertainment Guide',
        'source_url' => 'https://example.com/events',
        'priority' => 10,
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/events/weekend-roundup',
        'canonical_url' => 'https://example.com/events/weekend-roundup',
        'title' => 'Weekend Roundup',
        'content_text' => 'Upcoming meetings and events in Wichita include Comedy Open Mic and Wichita Thunder vs. Kansas City Mavericks.',
        'content_length' => 110,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Upcoming meetings and events in Wichita include Comedy Open Mic and Wichita Thunder vs. Kansas City Mavericks.',
        'content_length' => 110,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StreamingChatAnswerAgent::fake([
        'The next meetings are Comedy Open Mic and Wichita Thunder vs. Kansas City Mavericks.',
    ]);

    $query = 'What meetings are coming up in Wichita in the next 14 days?';

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: $query,
        city: $city,
        sources: collect([$source]),
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
        originalQuestion: $query,
    );

    expect($result['answer'])
        ->not->toContain('permit or formal review may be required');
});

it('does not use the procedural fallback for service alerts queries', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::factory()->create();

    StreamingChatAnswerAgent::fake([
        'The available sources indicate that a permit or formal review may be required. The full step-by-step process is not clearly described in the available sources.',
    ]);

    $query = 'What active service alerts or disruptions should residents in Wichita know about right now? Focus on roads, utilities, water, trash, and public services.';

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: $query,
        city: $city,
        sources: collect(),
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
        originalQuestion: $query,
    );

    expect($result['answer'])->toBe('I could not find the answer in the sources I checked.')
        ->and($result['answer'])->not->toContain('permit or formal review may be required')
        ->and($result['citations'])->toBe([]);
});

it('does not use the procedural fallback for permits summary queries', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::factory()->create();

    StreamingChatAnswerAgent::fake([
        'The available sources indicate that a permit or formal review may be required. They suggest that additional review may apply in some cases.',
    ]);

    $query = 'What new permits, rezonings, or major development projects were recently filed or approved in Wichita? Include status and key locations.';

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: $query,
        city: $city,
        sources: collect(),
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
        originalQuestion: $query,
    );

    expect($result['answer'])->toBe('I could not find the answer in the sources I checked.')
        ->and($result['answer'])->not->toContain('permit or formal review may be required')
        ->and($result['citations'])->toBe([]);
});
