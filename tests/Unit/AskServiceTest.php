<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\AnswerSynthesizer;
use App\Services\Chat\AskService;
use App\Services\Chat\ChatSourceSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
