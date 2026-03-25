<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AnswerSynthesizer;
use App\Services\Chat\AskService;
use App\Services\Chat\ChatEvidenceModeClassifier;
use App\Services\Chat\ChatSourceSelector;
use App\Services\Chat\ChatUpdatesAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses a single authenticated selector to synthesizer orchestration path for standard qa', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Trash Service',
        'source_url' => 'https://example.com/trash',
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'When is trash pickup?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->with(
            'When is trash pickup?',
            Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)),
            Mockery::type(Collection::class),
            Mockery::on(fn (User $resolvedUser): bool => $resolvedUser->is($user)),
            null,
            Mockery::type('callable'),
            'When is trash pickup?'
        )
        ->andReturn([
            'answer' => 'Trash pickup is on Monday.',
            'citations' => [
                [
                    'title' => 'Trash Service',
                    'source_url' => 'https://example.com/trash',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_trash',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'When is trash pickup?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('Trash pickup is on Monday.')
        ->and($response['citations'][0]['source_url'])->toBe('https://example.com/trash')
        ->and($response['conversation_id'])->toBe('conv_trash')
        ->and(array_keys($response))->toBe(['answer', 'citations', 'city', 'meta', 'conversation_id']);
});

it('routes event questions through the event-aware synthesis path even when no chat sources are selected', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $classifier = Mockery::mock(ChatEvidenceModeClassifier::class);
    $classifier->shouldReceive('classify')
        ->once()
        ->with('What city council, board, and public meetings are coming up in Wichita in the next 14 days?')
        ->andReturn(ChatEvidenceModeClassifier::EVENTS);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'What city council, board, and public meetings are coming up in Wichita in the next 14 days?')
        ->andReturn(collect());

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->with(
            'What city council, board, and public meetings are coming up in Wichita in the next 14 days?',
            Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)),
            Mockery::on(fn (Collection $sources): bool => $sources->isEmpty()),
            Mockery::on(fn (User $resolvedUser): bool => $resolvedUser->is($user)),
            null,
            Mockery::type('callable'),
            'What city council, board, and public meetings are coming up in Wichita in the next 14 days?'
        )
        ->andReturn([
            'answer' => 'Upcoming meetings include a city council workshop and a planning commission hearing.',
            'citations' => [
                [
                    'title' => 'Meeting Calendar',
                    'source_url' => 'https://example.com/meetings',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_events',
        ]);

    $updates = Mockery::mock(ChatUpdatesAnswerService::class);
    $updates->shouldNotReceive('answer');

    $service = new AskService($selector, $synthesizer, null, $classifier, $updates);
    $response = $service->answerStreamingForUser(
        'What city council, board, and public meetings are coming up in Wichita in the next 14 days?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toContain('Upcoming meetings')
        ->and($response['citations'][0]['source_url'])->toBe('https://example.com/meetings')
        ->and($response['conversation_id'])->toBe('conv_events');
});

it('routes digest-style updates queries to article-backed updates retrieval', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $classifier = Mockery::mock(ChatEvidenceModeClassifier::class);
    $classifier->shouldReceive('classify')
        ->once()
        ->with('Summarize the most important local updates in Wichita from the last 7 days.')
        ->andReturn(ChatEvidenceModeClassifier::UPDATES);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldNotReceive('select');

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldNotReceive('synthesizeStreaming');

    $updates = Mockery::mock(ChatUpdatesAnswerService::class);
    $updates->shouldReceive('answer')
        ->once()
        ->with('Summarize the most important local updates in Wichita from the last 7 days.', Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)))
        ->andReturn([
            'answer' => "Here are the most important local updates I found from the last 7 days:\n- Mar 24: Water Service Alert Update.\n- Mar 23: Rezoning Filing Update.\n- Mar 21: Downtown Project Approval.",
            'citations' => [
                [
                    'title' => 'Water Service Alert Update',
                    'source_url' => 'https://example.com/updates/service-alert-march-24',
                    'type' => 'html',
                ],
            ],
            'city' => [
                'id' => $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => 3,
                'pages_fetched' => 1,
                'cache_hits' => 0,
            ],
        ]);

    $streamed = '';
    $service = new AskService($selector, $synthesizer, null, $classifier, $updates);
    $response = $service->answerStreamingForUser(
        'Summarize the most important local updates in Wichita from the last 7 days.',
        $city->id,
        $user,
        null,
        function (string $delta) use (&$streamed): null {
            $streamed .= $delta;

            return null;
        },
    );

    expect($response['answer'])->toContain('Water Service Alert Update')
        ->and($streamed)->toBe($response['answer'])
        ->and($response['meta']['sources_used'])->toBe(3)
        ->and($response['conversation_id'])->toBeNull();
});

it('routes whats new this week queries to updates mode instead of event retrieval', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldNotReceive('select');

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldNotReceive('synthesizeStreaming');

    $updates = Mockery::mock(ChatUpdatesAnswerService::class);
    $updates->shouldReceive('answer')
        ->once()
        ->with('What’s new this week?', Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)))
        ->andReturn([
            'answer' => "Here are the most important local updates I found from the last 7 days:\n- Mar 24: Water Service Alert Update.",
            'citations' => [
                [
                    'title' => 'Water Service Alert Update',
                    'source_url' => 'https://example.com/updates/service-alert-march-24',
                    'type' => 'html',
                ],
            ],
            'city' => [
                'id' => $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => 1,
                'pages_fetched' => 1,
                'cache_hits' => 0,
            ],
        ]);

    $service = new AskService(
        $selector,
        $synthesizer,
        null,
        app(ChatEvidenceModeClassifier::class),
        $updates,
    );

    $response = $service->answerStreamingForUser(
        'What’s new this week?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toContain('Water Service Alert Update')
        ->and($response['citations'][0]['source_url'])->toBe('https://example.com/updates/service-alert-march-24')
        ->and($response['conversation_id'])->toBeNull();
});

it('routes service alert queries to updates mode instead of reference retrieval and fallback', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $classifier = Mockery::mock(ChatEvidenceModeClassifier::class);
    $classifier->shouldReceive('classify')
        ->once()
        ->with('What active service alerts or disruptions should residents in Wichita know about right now? Focus on roads, utilities, water, trash, and public services.')
        ->andReturn(ChatEvidenceModeClassifier::UPDATES);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldNotReceive('select');

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldNotReceive('synthesizeStreaming');

    $updates = Mockery::mock(ChatUpdatesAnswerService::class);
    $updates->shouldReceive('answer')
        ->once()
        ->andReturn([
            'answer' => 'I could not find active local service alerts or disruptions in the available article sources right now.',
            'citations' => [],
            'city' => [
                'id' => $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => 0,
                'pages_fetched' => 0,
                'cache_hits' => 0,
            ],
        ]);

    $service = new AskService($selector, $synthesizer, null, $classifier, $updates);
    $response = $service->answerStreamingForUser(
        'What active service alerts or disruptions should residents in Wichita know about right now? Focus on roads, utilities, water, trash, and public services.',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('I could not find active local service alerts or disruptions in the available article sources right now.')
        ->and($response['answer'])->not->toContain('Try a different wording or a more specific question.')
        ->and($response['citations'])->toBe([]);
});

it('drops legacy resources from the authenticated chat answer contract', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Trash Service',
        'source_url' => 'https://example.com/trash',
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'Who do I call about trash pickup?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->andReturn([
            'answer' => 'Call Public Works at (316) 555-1212.',
            'citations' => [
                [
                    'title' => 'Trash Service',
                    'source_url' => 'https://example.com/trash',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_trash',
            'resources' => [
                [
                    'label' => 'Legacy Resource',
                    'url' => 'https://example.com/legacy',
                ],
            ],
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'Who do I call about trash pickup?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('Call Public Works at (316) 555-1212.')
        ->and($response['citations'][0]['source_url'])->toBe('https://example.com/trash')
        ->and(array_keys($response))->toBe(['answer', 'citations', 'city', 'meta', 'conversation_id'])
        ->and(array_key_exists('resources', $response))->toBeFalse();
});

it('normalizes generic city phrasing before authenticated retrieval and synthesis', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Tenant Rights',
        'source_url' => 'https://example.com/tenant-rights',
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'What are tenant rights in Wichita?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->with(
            'What are tenant rights in Wichita?',
            Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)),
            Mockery::type(Collection::class),
            Mockery::on(fn (User $resolvedUser): bool => $resolvedUser->is($user)),
            null,
            Mockery::type('callable'),
            'What are tenant rights in my city?'
        )
        ->andReturn([
            'answer' => 'Tenant rights information is available from the housing resource page.',
            'citations' => [
                [
                    'title' => 'Tenant Rights',
                    'source_url' => 'https://example.com/tenant-rights',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_tenant',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'What are tenant rights in my city?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('Tenant rights information is available from the housing resource page.')
        ->and($response['citations'][0]['source_url'])->toBe('https://example.com/tenant-rights')
        ->and($response['conversation_id'])->toBe('conv_tenant');
});

it('does not widen source selection for authenticated procedural questions', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Permit Center',
        'source_url' => 'https://example.com/demolition-permit',
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'How do I get a demolition permit?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->andReturn([
            'answer' => 'You can apply for a demolition permit through the city permit center.',
            'citations' => [
                [
                    'title' => 'Permit Center',
                    'source_url' => 'https://example.com/demolition-permit',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_demo',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'How do I get a demolition permit?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('You can apply for a demolition permit through the city permit center.')
        ->and($response['citations'][0]['source_url'])->toBe('https://example.com/demolition-permit')
        ->and($response['conversation_id'])->toBe('conv_demo');
});

it('suppresses streaming citations when answer confidence is below the display threshold', function () {
    config()->set('chat.source_display_min_confidence', 0.85);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Permit Center',
        'source_url' => 'https://example.com/demolition-permit',
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'How do I get a demolition permit?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->with(
            'How do I get a demolition permit?',
            Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)),
            Mockery::type(Collection::class),
            Mockery::on(fn (User $resolvedUser): bool => $resolvedUser->is($user)),
            null,
            Mockery::type('callable'),
            'How do I get a demolition permit?'
        )
        ->andReturn([
            'answer' => 'You can apply for a demolition permit through the city permit center.',
            'citations' => [
                [
                    'title' => 'Permit Center',
                    'source_url' => 'https://example.com/demolition-permit',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.62,
            'source_mode' => 'local',
            'conversation_id' => 'conv_demo',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'How do I get a demolition permit?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('You can apply for a demolition permit through the city permit center.')
        ->and($response['citations'])->toBe([])
        ->and($response['conversation_id'])->toBe('conv_demo')
        ->and($response['meta']['pages_fetched'])->toBe(0);
});

it('does not reuse unrelated conversation memory between demolition and meetings questions', function () {
    config()->set('chat.memory_enabled', true);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $demolitionSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Permit Center',
        'source_url' => 'https://example.com/demolition-permit',
        'is_active' => true,
    ]);

    $meetingSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Meeting Calendar',
        'source_url' => 'https://example.com/meetings',
        'is_active' => true,
    ]);

    $existingConversationId = (string) Str::uuid7();
    $firstAnswer = 'You can apply for a demolition permit through the city permit center.';
    $secondAnswer = 'The next public meetings are listed on the city meeting calendar.';

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'How do I get a demolition permit?')
        ->andReturn(collect([$demolitionSource]));
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'What upcoming meetings are scheduled?')
        ->andReturn(collect([$meetingSource]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->with(
            'How do I get a demolition permit?',
            Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)),
            Mockery::type(Collection::class),
            Mockery::on(fn (User $resolvedUser): bool => $resolvedUser->is($user)),
            null,
            Mockery::type('callable'),
            'How do I get a demolition permit?'
        )
        ->andReturn([
            'answer' => $firstAnswer,
            'citations' => [
                [
                    'title' => 'Permit Center',
                    'source_url' => 'https://example.com/demolition-permit',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => $existingConversationId,
        ]);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->with(
            'What upcoming meetings are scheduled?',
            Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)),
            Mockery::type(Collection::class),
            Mockery::on(fn (User $resolvedUser): bool => $resolvedUser->is($user)),
            null,
            Mockery::type('callable'),
            'What upcoming meetings are scheduled?'
        )
        ->andReturn([
            'answer' => $secondAnswer,
            'citations' => [
                [
                    'title' => 'Meeting Calendar',
                    'source_url' => 'https://example.com/meetings',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_meetings',
        ]);

    $service = new AskService($selector, $synthesizer);

    $firstResponse = $service->answerStreamingForUser(
        'How do I get a demolition permit?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    DB::table('agent_conversations')->insert([
        'id' => $existingConversationId,
        'user_id' => $user->id,
        'title' => 'Demolition permit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $existingConversationId,
            'user_id' => $user->id,
            'agent' => 'App\\Services\\Chat\\Agents\\StreamingChatAnswerAgent',
            'role' => 'user',
            'content' => 'How do I get a demolition permit?',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $existingConversationId,
            'user_id' => $user->id,
            'agent' => 'App\\Services\\Chat\\Agents\\StreamingChatAnswerAgent',
            'role' => 'assistant',
            'content' => $firstAnswer,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $secondResponse = $service->answerStreamingForUser(
        'What upcoming meetings are scheduled?',
        $city->id,
        $user,
        $firstResponse['conversation_id'],
        fn () => null,
    );

    expect($firstResponse['conversation_id'])->toBe($existingConversationId)
        ->and($secondResponse['answer'])->toBe($secondAnswer)
        ->and($secondResponse['answer'])->not->toContain('demolition permit')
        ->and($secondResponse['citations'][0]['source_url'])->toBe('https://example.com/meetings')
        ->and($secondResponse['conversation_id'])->toBe('conv_meetings');
});

it('reuses conversation memory for short contextual follow-up questions', function () {
    config()->set('chat.memory_enabled', true);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Meeting Calendar',
        'source_url' => 'https://example.com/meetings',
        'is_active' => true,
    ]);

    $existingConversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $existingConversationId,
        'user_id' => $user->id,
        'title' => 'Meetings',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $existingConversationId,
            'user_id' => $user->id,
            'agent' => 'App\\Services\\Chat\\Agents\\StreamingChatAnswerAgent',
            'role' => 'user',
            'content' => 'What upcoming meetings are scheduled?',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $existingConversationId,
            'user_id' => $user->id,
            'agent' => 'App\\Services\\Chat\\Agents\\StreamingChatAnswerAgent',
            'role' => 'assistant',
            'content' => 'The next public meetings are listed on the city meeting calendar.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'What about next week?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->with(
            'What about next week?',
            Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)),
            Mockery::type(Collection::class),
            Mockery::on(fn (User $resolvedUser): bool => $resolvedUser->is($user)),
            $existingConversationId,
            Mockery::type('callable'),
            'What about next week?'
        )
        ->andReturn([
            'answer' => 'Next week includes a city council workshop and a planning commission meeting.',
            'citations' => [
                [
                    'title' => 'Meeting Calendar',
                    'source_url' => 'https://example.com/meetings',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => $existingConversationId,
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'What about next week?',
        $city->id,
        $user,
        $existingConversationId,
        fn () => null,
    );

    expect($response['answer'])->toContain('Next week')
        ->and($response['conversation_id'])->toBe($existingConversationId);
});

it('returns a clean fallback for unrelated empty-source reference queries without carrying the previous conversation', function () {
    config()->set('chat.memory_enabled', true);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $existingConversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $existingConversationId,
        'user_id' => $user->id,
        'title' => 'Demolition permit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $existingConversationId,
            'user_id' => $user->id,
            'agent' => 'App\\Services\\Chat\\Agents\\StreamingChatAnswerAgent',
            'role' => 'user',
            'content' => 'How do I get a demolition permit?',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'Who do I call about trash pickup?')
        ->andReturn(collect());

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldNotReceive('synthesizeStreaming');

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'Who do I call about trash pickup?',
        $city->id,
        $user,
        $existingConversationId,
        fn () => null,
    );

    expect($response['answer'])->toBe('I could not find the answer in the sources I checked. Try a different wording or a more specific question.')
        ->and($response['citations'])->toBe([])
        ->and($response['conversation_id'])->toBeNull();
});

it('returns the deterministic fallback when the synthesizer yields an empty answer', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Trash Service',
        'source_url' => 'https://example.com/trash',
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'Who do I call about trash pickup?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->andReturn([
            'answer' => '',
            'citations' => [
                [
                    'title' => 'Trash Service',
                    'source_url' => 'https://example.com/trash',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_trash',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'Who do I call about trash pickup?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('I could not find the answer in the sources I checked. Try a different wording or a more specific question.')
        ->and($response['citations'])->toBe([])
        ->and($response['meta']['sources_used'])->toBe(1)
        ->and($response['meta']['pages_fetched'])->toBe(0)
        ->and($response['conversation_id'])->toBe('conv_trash');
});

it('returns the deterministic fallback when the synthesizer responds with no-answer phrasing', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);
    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Meeting Calendar',
        'source_url' => 'https://example.com/meetings',
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'What upcoming meetings are scheduled?')
        ->andReturn(collect([$source]));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')
        ->once()
        ->andReturn([
            'answer' => 'I could not find the answer in the sources I checked.',
            'citations' => [
                [
                    'title' => 'Meeting Calendar',
                    'source_url' => 'https://example.com/meetings',
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.95,
            'source_mode' => 'local',
            'conversation_id' => 'conv_meetings',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answerStreamingForUser(
        'What upcoming meetings are scheduled?',
        $city->id,
        $user,
        null,
        fn () => null,
    );

    expect($response['answer'])->toBe('I could not find the answer in the sources I checked. Try a different wording or a more specific question.')
        ->and($response['citations'])->toBe([])
        ->and($response['meta']['sources_used'])->toBe(1)
        ->and($response['conversation_id'])->toBe('conv_meetings');
});
