<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\SimilaritySearch;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('always includes local similarity search when enabled', function () {
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);

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

    expect($tools->contains(fn ($tool): bool => $tool instanceof SimilaritySearch))->toBeTrue()
        ->and($tools->contains(fn ($tool): bool => $tool instanceof WebSearch))->toBeFalse();
});

it('does not add web search for non-event questions', function () {
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);
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

    expect($tools->contains(fn ($tool): bool => $tool instanceof WebSearch))->toBeFalse();
});

it('uses event web fallback domains only for event questions with empty local results', function () {
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);
    config()->set('chat.events.enabled', true);
    config()->set('chat.events.intent_mode', 'intent');
    config()->set('chat.events.web_fallback.enabled', true);
    config()->set('chat.events.web_fallback.only_when_local_empty', true);
    config()->set('chat.events.web_fallback.allowed_domains_mode', 'city_event_sources_merged');
    config()->set('chat.events.web_fallback.allowed_domains', ['state.kan.us']);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'timezone' => 'America/Chicago',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    \App\Models\EventSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.visitwichita.com/events',
        'is_active' => true,
    ]);

    \App\Models\EventSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://calendar.wichita.gov',
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

    $webSearch = $tools->first(fn ($tool): bool => $tool instanceof WebSearch);
    $domains = collect($webSearch?->allowedDomains ?? [])->sort()->values()->all();

    expect($webSearch)->toBeInstanceOf(WebSearch::class)
        ->and($domains)->toBe([
            'calendar.wichita.gov',
            'state.kan.us',
            'visitwichita.com',
        ]);
});

it('keeps the default chat model for both event and non-event questions', function () {
    config()->set('chat.model', 'gpt-4o-mini');
    config()->set('chat.web_search_model', 'gpt-5-mini');

    $synthesizer = app(AnswerSynthesizer::class);
    $method = new ReflectionMethod(AnswerSynthesizer::class, 'chatModelForQuestion');
    $method->setAccessible(true);

    $defaultModel = $method->invoke($synthesizer, 'When is trash pickup?', []);
    $eventModel = $method->invoke($synthesizer, "What's going on this weekend?", ['intent' => true, 'local_total' => 0]);

    expect($defaultModel)->toBe('gpt-4o-mini')
        ->and($eventModel)->toBe('gpt-4o-mini');
});
