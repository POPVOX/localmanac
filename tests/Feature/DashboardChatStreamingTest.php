<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AskService;
use Livewire\Livewire;

it('streams answers for authenticated dashboard users and persists conversation id', function () {
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
            User $requestUser,
            ?string $conversationId,
            callable $onDelta
        ) use ($city, $user): array {
            expect($question)->toBe('When is trash pickup?')
                ->and($citySelector)->toBe($city->id)
                ->and($requestUser->is($user))->toBeTrue()
                ->and($conversationId)->toBeNull();

            $onDelta('Trash pickup ');
            $onDelta('is on Monday.');

            return [
                'answer' => 'Trash pickup is on Monday.',
                'citations' => [
                    [
                        'title' => 'Recycling & Trash',
                        'source_url' => 'https://example.com/recycling',
                        'type' => 'html',
                    ],
                ],
                'resources' => [
                    [
                        'type' => 'link',
                        'label' => 'Open source page',
                        'value' => 'Recycling & Trash',
                        'url' => 'https://example.com/recycling',
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
        ->assertSee('Open source page')
        ->assertSee('Recycling & Trash')
        ->assertSeeHtml('data-chat-role="assistant"');

    expect(session()->get('chat.conversation_id'))->toBe('conv_123');
});
