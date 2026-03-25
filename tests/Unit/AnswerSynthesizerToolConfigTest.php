<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\SimilaritySearch;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('always includes local similarity search when enabled', function () {
    config()->set('chat.tools.similarity.enabled', true);

    $city = new City;
    $city->name = 'Wichita';

    $source = new ChatSource;
    $source->id = 1;
    $source->source_url = 'https://example.com/recycling';

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        'When is trash pickup?'
    ));

    expect($tools->contains(fn ($tool): bool => $tool instanceof SimilaritySearch))->toBeTrue();
});

it('does not include web search tool for any question', function () {
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.events.enabled', true);

    $city = new City;
    $city->name = 'Wichita';

    $source = new ChatSource;
    $source->id = 2;
    $source->source_url = 'https://example.com/permits';

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        'How do I get a demolition permit?'
    ));

    expect($tools->every(fn ($tool): bool => ! ($tool instanceof \Laravel\Ai\Providers\Tools\WebSearch)))->toBeTrue();
});

it('does not include web search tool even for event questions', function () {
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.events.enabled', true);
    config()->set('chat.events.intent_mode', 'intent');

    $city = City::factory()->create([
        'name' => 'Wichita',
        'timezone' => 'America/Chicago',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        "What's going on this weekend?"
    ));

    expect($tools->every(fn ($tool): bool => ! ($tool instanceof \Laravel\Ai\Providers\Tools\WebSearch)))->toBeTrue();
});

it('no longer has chatModelForQuestion method since model switching was removed', function () {
    expect(method_exists(AnswerSynthesizer::class, 'chatModelForQuestion'))->toBeFalse();
});
