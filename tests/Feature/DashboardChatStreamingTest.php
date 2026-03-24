<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AskService;
use Livewire\Livewire;
use Symfony\Component\DomCrawler\Crawler;

it('uses the streaming chat path for authenticated dashboard users and stores conversation memory', function () {
    config()->set('chat.streaming_enabled', true);
    config()->set('chat.memory_enabled', true);
    config()->set('chat.memory_session_key', 'chat.conversation_id');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $service = mock(AskService::class);
    $service->shouldReceive('answerStreamingForUser')
        ->once()
        ->andReturnUsing(function (
            string $question,
            int|string|null $citySelector,
            User $resolvedUser,
            ?string $conversationId,
            callable $onDelta
        ) use ($city, $user): array {
            expect($question)->toBe('When is trash pickup?')
                ->and($citySelector)->toBe($city->id)
                ->and($resolvedUser->is($user))->toBeTrue()
                ->and($conversationId)->toBeNull();

            $onDelta('Trash pickup is on Monday.');

            return [
                'answer' => 'Trash pickup is on Monday.',
                'citations' => [
                    [
                        'title' => 'Recycling & Trash',
                        'source_url' => 'https://example.com/recycling',
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
                'conversation_id' => 'conv_123',
            ];
        });

    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('question', 'When is trash pickup?')
        ->call('ask')
        ->assertSet('conversationId', 'conv_123')
        ->assertSee('Trash pickup is on Monday.')
        ->assertSee('Recycling & Trash')
        ->assertSeeHtml('data-chat-role="assistant"');

    expect(session('chat.conversation_id'))->toBe('conv_123');
});

it('uses the authenticated chat path even when the legacy streaming flag is disabled', function () {
    config()->set('chat.streaming_enabled', false);
    config()->set('chat.memory_enabled', true);

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $service = mock(AskService::class);
    $service->shouldReceive('answerStreamingForUser')
        ->once()
        ->andReturn([
            'answer' => 'Trash pickup is on Monday.',
            'citations' => [],
            'city' => [
                'id' => $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => 1,
                'pages_fetched' => 0,
                'cache_hits' => 0,
            ],
            'conversation_id' => 'conv_456',
        ]);

    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('question', 'When is trash pickup?')
        ->call('ask')
        ->assertSet('conversationId', 'conv_456')
        ->assertSee('Trash pickup is on Monday.');
});

it('keeps dashboard prompt chips on the streaming path without a fallback intent', function () {
    config()->set('chat.streaming_enabled', true);
    config()->set('chat.memory_enabled', true);

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $prompt = 'What new permits, rezonings, or major development projects were recently filed or approved in Wichita? Include status and key locations.';

    $service = mock(AskService::class);
    $service->shouldReceive('answerStreamingForUser')
        ->once()
        ->andReturnUsing(function (
            string $question,
            int|string|null $citySelector,
            User $resolvedUser,
            ?string $conversationId,
            callable $onDelta
        ) use ($city, $user, $prompt): array {
            expect($question)->toBe($prompt)
                ->and($citySelector)->toBe($city->id)
                ->and($resolvedUser->is($user))->toBeTrue()
                ->and($conversationId)->toBeNull();

            $onDelta('Here are project updates.');

            return [
                'answer' => 'Here are project updates.',
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
                'conversation_id' => null,
            ];
        });

    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->call('applyPrompt', $prompt)
        ->call('ask')
        ->assertSee('Here are project updates.');
});

it('renders a fresh assistant answer and citations for each new dashboard query', function () {
    config()->set('chat.streaming_enabled', true);
    config()->set('chat.memory_enabled', true);

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $responses = [
        [
            'question' => 'How do I get a demolition permit?',
            'answer' => 'Submit the demolition permit application through the city permit portal and wait for approval before teardown starts.',
            'citation' => [
                'title' => 'Demolition Permit Portal',
                'source_url' => 'https://example.com/demolition-permit',
                'type' => 'html',
            ],
        ],
        [
            'question' => 'What upcoming meetings are scheduled?',
            'answer' => 'Upcoming meetings include a city council workshop on April 2 and a planning commission meeting on April 4.',
            'citation' => [
                'title' => 'Upcoming Public Meetings',
                'source_url' => 'https://example.com/meetings',
                'type' => 'html',
            ],
        ],
    ];

    $service = mock(AskService::class);
    $service->shouldReceive('answerStreamingForUser')
        ->twice()
        ->andReturnUsing(function (
            string $question,
            int|string|null $citySelector,
            User $resolvedUser,
            ?string $conversationId,
            callable $onDelta
        ) use ($responses, $city, $user): array {
            static $callCount = 0;

            $response = $responses[$callCount];

            expect($question)->toBe($response['question'])
                ->and($citySelector)->toBe($city->id)
                ->and($resolvedUser->is($user))->toBeTrue();

            $onDelta($response['answer']);

            $callCount++;

            return [
                'answer' => $response['answer'],
                'citations' => [$response['citation']],
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
                'conversation_id' => $conversationId,
            ];
        });

    $this->instance(AskService::class, $service);

    $component = Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('question', $responses[0]['question'])
        ->call('ask')
        ->set('question', $responses[1]['question'])
        ->call('ask')
        ->assertCount('messages', 4);

    $messages = $component->get('messages');

    expect($messages)->toHaveCount(4)
        ->and(array_column($messages, 'role'))->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and(array_unique(array_column($messages, 'id')))->toHaveCount(4)
        ->and($messages[1]['content'])->toContain('demolition permit')
        ->and($messages[1]['citations'][0]['title'])->toBe('Demolition Permit Portal')
        ->and($messages[3]['content'])->toContain('Upcoming meetings include a city council workshop')
        ->and($messages[3]['content'])->not->toContain('demolition permit')
        ->and($messages[3]['citations'][0]['title'])->toBe('Upcoming Public Meetings');

    $html = $component->html();

    expect($html)
        ->toContain('wire:key="chat-message-'.$messages[1]['id'].'"')
        ->toContain('wire:key="chat-message-'.$messages[3]['id'].'"');

    $assistantMessages = (new Crawler($html))->filter('[data-chat-role="assistant"]');

    expect($assistantMessages->count())->toBe(2);

    $secondAssistant = $assistantMessages->eq(1);
    $secondAssistantText = trim($secondAssistant->text('', true));
    $secondAssistantCitations = $secondAssistant->filter('a')->each(
        fn (Crawler $node): string => trim($node->text('', true))
    );

    expect($secondAssistantText)
        ->toContain('Upcoming meetings include a city council workshop on April 2')
        ->not->toContain('demolition permit')
        ->not->toContain('Demolition Permit Portal')
        ->toContain('Upcoming Public Meetings');

    expect($secondAssistantCitations)
        ->toContain('Upcoming Public Meetings')
        ->not->toContain('Demolition Permit Portal');
});
