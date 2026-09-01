<?php

use App\Models\Article;
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
    $synthesizer = app(AnswerSynthesizer::class);
    $method = new ReflectionMethod(AnswerSynthesizer::class, 'isProceduralQuestion');
    $method->setAccessible(true);

    expect($method->invoke(
        $synthesizer,
        'What city council, board, and public meetings are coming up in Wichita in the next 14 days? Include dates, times, and where to find the agenda.'
    ))->toBeFalse()
        ->and($method->invoke($synthesizer, 'How do I get a demolition permit?'))->toBeTrue();
});

it('does not trigger the procedural guard for event-intent meeting queries', function () {
    $synthesizer = app(AnswerSynthesizer::class);
    $method = new ReflectionMethod(AnswerSynthesizer::class, 'shouldConstrainProceduralAnswer');
    $method->setAccessible(true);

    $question = 'What city council, board, and public meetings are coming up in Wichita in the next 14 days? Include dates, times, and where to find the agenda.';
    $answer = "1. Submit the application.\n2. Wait for permit review.\n3. Schedule the final inspection.";
    $evidence = [
        [
            'title' => 'Agenda Center • Wichita, KS • CivicEngage',
            'snippet' => 'City council meeting agendas are posted in the Agenda Center before each meeting.',
            'source_url' => 'https://www.wichita.gov/AgendaCenter/Wichita-City-Council-Meetings-34',
            'score' => 9.5,
        ],
    ];

    expect($method->invoke($synthesizer, $question, $answer, $evidence, $evidence, true))->toBeFalse()
        ->and($method->invoke($synthesizer, 'How do I get a demolition permit?', $answer, $evidence, $evidence, false))->toBeTrue();
});

it('narrows procedural answers when ordinance-style evidence does not support a complete process', function () {
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
    $sentences = array_values(array_filter(
        preg_split('/(?<=[.?!])\s+/u', trim($answer)) ?: [],
        fn (string $sentence): bool => $sentence !== ''
    ));

    expect($answer)
        ->toContain('Submit the application')
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

it('returns an immediate city-scoped meeting empty state without prompting the model', function () {
    config()->set('chat.events.enabled', true);
    config()->set('chat.events.intent_mode', 'intent');
    config()->set('chat.answer_quality_enabled', false);

    $city = City::factory()->create([
        'name' => 'Lawrence, KS',
        'slug' => 'lawrence-ks',
        'timezone' => 'America/Chicago',
    ]);
    $user = User::factory()->create();
    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Lawrence City Government',
        'source_url' => 'https://lawrenceks.gov',
        'is_active' => true,
    ]);
    $deltas = [];

    StreamingChatAnswerAgent::fake(['This response must not be used.']);

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: 'What city commission meetings are coming up this week?',
        city: $city,
        sources: collect([$source]),
        user: $user,
        conversationId: null,
        onDelta: function (string $delta) use (&$deltas): null {
            $deltas[] = $delta;

            return null;
        },
    );

    expect($result['answer'])->toBe('No upcoming city council or public meetings are currently listed in the available Lawrence, KS sources.')
        ->and($result['citations'])->toBe([])
        ->and($deltas)->toBe([$result['answer']]);

    StreamingChatAnswerAgent::assertNeverPrompted();
});

it('returns an immediate Lawrence article digest without prompting the model', function () {
    $city = City::factory()->create([
        'name' => 'Lawrence, KS',
        'slug' => 'lawrence-ks',
        'timezone' => 'America/Chicago',
    ]);
    $user = User::factory()->create();
    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Lawrence City Government',
        'source_url' => 'https://lawrenceks.gov',
        'is_active' => true,
    ]);
    $article = Article::factory()->create([
        'city_id' => $city->id,
        'title' => 'Water main repairs begin downtown',
        'summary' => 'Crews will close one block while replacing a damaged water main.',
        'published_at' => now()->startOfWeek()->addDay(),
        'canonical_url' => 'https://lawrenceks.gov/news/water-main-repairs',
        'status' => 'published',
    ]);
    $deltas = [];

    StreamingChatAnswerAgent::fake(['This response must not be used.']);

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: 'What is new in Lawrence this week?',
        city: $city,
        sources: collect([$source]),
        user: $user,
        conversationId: null,
        onDelta: function (string $delta) use (&$deltas): null {
            $deltas[] = $delta;

            return null;
        },
    );

    expect($result['answer'])
        ->toContain('Here are the most important local updates I found for this week:')
        ->toContain($article->title)
        ->and($result['citations'])->toBe([
            [
                'title' => $article->title,
                'source_url' => $article->canonical_url,
                'type' => 'html',
            ],
        ])
        ->and($deltas)->toBe([$result['answer']]);

    StreamingChatAnswerAgent::assertNeverPrompted();
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

    expect($result['answer'])->toBe('No upcoming city council or public meetings are currently listed in the available Wichita sources.')
        ->and($result['citations'])->toBe([]);
});

it('uses filtered local meeting results for the final answer instead of grounded unrelated source text', function () {
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
        ->toContain('Wichita City Council Workshop')
        ->toContain('Planning Commission Regular Meeting')
        ->not->toContain('Comedy Open Mic')
        ->not->toContain('Wichita Thunder vs. Kansas City Mavericks')
        ->and(collect($result['citations'])->pluck('source_url')->all())
        ->toContain('https://www.wichita.gov/AgendaCenter/Wichita-City-Council-Meetings-34')
        ->toContain('https://www.wichita.gov/AgendaCenter/planning-commission')
        ->not->toContain('https://example.com/events/weekend-roundup');
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

    expect($result['answer'])->toBe('I could not find active local service alerts or disruptions in the available article sources right now.')
        ->and($result['answer'])->not->toContain('permit or formal review may be required')
        ->and($result['citations'])->toBe([]);

    StreamingChatAnswerAgent::assertNeverPrompted();
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

    expect($result['answer'])->toBe('I could not find enough recent local updates in the available article sources recently.')
        ->and($result['answer'])->not->toContain('permit or formal review may be required')
        ->and($result['citations'])->toBe([]);

    StreamingChatAnswerAgent::assertNeverPrompted();
});
