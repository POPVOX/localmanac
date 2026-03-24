<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AskService;
use Livewire\Livewire;

it('does not reuse stored dashboard conversation memory and can clear the thread', function () {
    config()->set('chat.streaming_enabled', true);
    config()->set('chat.memory_enabled', true);
    config()->set('chat.memory_session_key', 'chat.conversation_id');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    session()->put('chat.conversation_id', 'conv_existing');

    $service = mock(AskService::class);
    $service->shouldReceive('answer')
        ->once()
        ->andReturnUsing(function (
            string $question,
            ?int $cityId,
            ?string $citySlug,
            ?string $fallbackIntent
        ) use ($city): array {
            expect($question)->toBe('Any updates?')
                ->and($cityId)->toBe($city->id)
                ->and($citySlug)->toBeNull()
                ->and($fallbackIntent)->toBeNull();

            return [
                'answer' => 'Here are updates.',
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
            ];
        });

    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('question', 'Any updates?')
        ->call('ask')
        ->assertSet('conversationId', null)
        ->assertSee('Here are updates.')
        ->call('startNewConversation')
        ->assertSet('conversationId', null)
        ->assertSet('messages', []);

    expect(session()->get('chat.conversation_id'))->toBe('conv_existing');
});
