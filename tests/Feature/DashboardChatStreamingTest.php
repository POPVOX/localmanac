<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AskService;
use Livewire\Livewire;

it('streams answers for authenticated dashboard users without reusing conversation memory', function () {
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
    $service->shouldReceive('answer')
        ->once()
        ->andReturnUsing(function (
            string $question,
            ?int $cityId,
            ?string $citySlug,
            ?string $fallbackIntent
        ) use ($city): array {
            expect($question)->toBe('When is trash pickup?')
                ->and($cityId)->toBe($city->id)
                ->and($citySlug)->toBeNull()
                ->and($fallbackIntent)->toBeNull();

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
            ];
        });

    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class)
        ->set('cityId', $city->id)
        ->set('question', 'When is trash pickup?')
        ->call('ask')
        ->assertSet('conversationId', null)
        ->assertSee('Trash pickup is on Monday.')
        ->assertSee('Open source page')
        ->assertSee('Recycling & Trash')
        ->assertSeeHtml('data-chat-role="assistant"');

    expect(session()->has('chat.conversation_id'))->toBeFalse();
});

it('passes explicit fallback intent for unchanged prompt chips', function () {
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
    $service->shouldReceive('answer')
        ->once()
        ->andReturnUsing(function (
            string $question,
            ?int $cityId,
            ?string $citySlug,
            ?string $fallbackIntent
        ) use ($city, $prompt): array {
            expect($question)->toBe($prompt)
                ->and($cityId)->toBe($city->id)
                ->and($citySlug)->toBeNull()
                ->and($fallbackIntent)->toBe('permits_projects');

            return [
                'answer' => 'Here are project updates.',
                'citations' => [],
                'resources' => [],
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
        ->call('applyPrompt', $prompt, 'permits_projects')
        ->call('ask')
        ->assertSee('Here are project updates.');
});
