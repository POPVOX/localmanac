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

it('keeps an uncited answer but suppresses unrelated source links', function () {
    config()->set('chat.source_display_min_confidence', 0.85);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $sources = ChatSource::factory()->count(2)->create([
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    $selector = Mockery::mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')
        ->once()
        ->with($city->id, 'Who is the largest employer in Wichita?')
        ->andReturn(new Collection($sources->all()));

    $synthesizer = Mockery::mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesize')
        ->once()
        ->with('Who is the largest employer in Wichita?', Mockery::on(fn (City $resolvedCity): bool => $resolvedCity->is($city)), Mockery::type(Collection::class))
        ->andReturn([
            'answer' => 'Spirit AeroSystems is likely the largest employer in Wichita.',
            'citations' => [],
            'resources' => [
                [
                    'type' => 'link',
                    'label' => 'Open source page',
                    'value' => 'About Wichita Public Schools',
                    'url' => 'https://www.usd259.org/about-wps',
                ],
            ],
            'confidence' => 0.74,
            'source_mode' => 'web',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answer('Who is the largest employer in Wichita?', $city->id);

    expect($response['answer'])->toBe('Spirit AeroSystems is likely the largest employer in Wichita.')
        ->and($response['citations'])->toBe([])
        ->and($response['resources'])->toBe([])
        ->and($response['meta']['pages_fetched'])->toBe(0);
});

it('suppresses citations and resources when answer confidence is below the display threshold', function () {
    config()->set('chat.source_display_min_confidence', 0.85);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

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
    $synthesizer->shouldReceive('synthesize')
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
            'resources' => [
                [
                    'type' => 'link',
                    'label' => 'Open permit page',
                    'value' => 'Permit Center',
                    'url' => 'https://example.com/demolition-permit',
                ],
            ],
            'confidence' => 0.62,
            'source_mode' => 'local',
        ]);

    $service = new AskService($selector, $synthesizer);
    $response = $service->answer('How do I get a demolition permit?', $city->id);

    expect($response['answer'])->toBe('You can apply for a demolition permit through the city permit center.')
        ->and($response['citations'])->toBe([])
        ->and($response['resources'])->toBe([])
        ->and($response['meta']['pages_fetched'])->toBe(0);
});

it('suppresses streaming source links when confidence is below the display threshold', function () {
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
        ->andReturn([
            'answer' => 'You can apply for a demolition permit through the city permit center.',
            'citations' => [
                [
                    'title' => 'Permit Center',
                    'source_url' => 'https://example.com/demolition-permit',
                    'type' => 'html',
                ],
            ],
            'resources' => [
                [
                    'type' => 'link',
                    'label' => 'Open permit page',
                    'value' => 'Permit Center',
                    'url' => 'https://example.com/demolition-permit',
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
        ->and($response['resources'])->toBe([])
        ->and($response['conversation_id'])->toBe('conv_demo')
        ->and($response['meta']['pages_fetched'])->toBe(0);
});
