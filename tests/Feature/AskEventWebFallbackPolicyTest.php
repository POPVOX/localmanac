<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use App\Services\Chat\AnswerSynthesizer;
use App\Services\Chat\Tools\EventSearchTool;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('does not add web search for event asks even when local events exist', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:00:00', 'America/Chicago'));

    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.events.enabled', true);
    config()->set('chat.events.intent_mode', 'intent');
    config()->set('chat.events.max_results', 8);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'timezone' => 'America/Chicago',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Weekend Festival',
        'starts_at' => Carbon::parse('2026-02-14 10:00:00', 'America/Chicago'),
        'ends_at' => Carbon::parse('2026-02-14 18:00:00', 'America/Chicago'),
        'source_hash' => sha1('weekend-festival'),
    ]);

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        "What's going on this weekend?"
    ));

    expect($tools->contains(fn ($tool): bool => $tool instanceof EventSearchTool))->toBeTrue()
        ->and($tools->every(fn ($tool): bool => ! ($tool instanceof \Laravel\Ai\Providers\Tools\WebSearch)))->toBeTrue();
});

it('never adds web search even for event questions with empty local results', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-12 10:00:00', 'America/Chicago'));

    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.events.enabled', true);
    config()->set('chat.events.intent_mode', 'intent');
    config()->set('chat.events.max_results', 8);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'timezone' => 'America/Chicago',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.wichita.gov',
        'is_active' => true,
    ]);

    EventSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.visitwichita.com/events',
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

    expect($tools->contains(fn ($tool): bool => $tool instanceof EventSearchTool))->toBeTrue()
        ->and($tools->every(fn ($tool): bool => ! ($tool instanceof \Laravel\Ai\Providers\Tools\WebSearch)))->toBeTrue();
});
