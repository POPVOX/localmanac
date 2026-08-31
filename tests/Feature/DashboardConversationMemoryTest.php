<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AskService;
use Livewire\Livewire;

it('reuses stored dashboard conversation memory and clears it on reset', function () {
    config()->set('chat.streaming_enabled', true);
    config()->set('chat.memory_enabled', true);
    config()->set('chat.memory_session_key', 'chat.conversation_id');

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $user = User::factory()->create();
    $user->cities()->attach($city);
    $this->actingAs($user);

    session()->put('chat.conversation_id.city.'.$city->id, 'conv_existing');

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
            expect($question)->toBe('Any updates?')
                ->and($citySelector)->toBe($city->id)
                ->and($resolvedUser->is($user))->toBeTrue()
                ->and($conversationId)->toBe('conv_existing');

            $onDelta('Here are updates.');

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
                'conversation_id' => 'conv_existing',
            ];
        });

    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->assertSet('conversationId', 'conv_existing')
        ->set('question', 'Any updates?')
        ->call('ask')
        ->assertSet('conversationId', 'conv_existing')
        ->assertSee('Here are updates.')
        ->call('startNewConversation')
        ->assertSet('conversationId', null)
        ->assertSet('messages', []);

    expect(session()->has('chat.conversation_id.city.'.$city->id))->toBeFalse();
});
