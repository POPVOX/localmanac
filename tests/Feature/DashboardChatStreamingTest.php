<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AskService;
use Livewire\Livewire;

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
