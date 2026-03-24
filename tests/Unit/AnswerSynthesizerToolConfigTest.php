<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\SimilaritySearch;
use Tests\TestCase;

uses(TestCase::class);

it('configures web search tool with location, max searches, and allowed domains for fresh intent', function () {
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);
    config()->set('chat.tools.web_search.only_when_fresh_intent', true);
    config()->set('chat.tools.web_search.max_searches', 5);
    config()->set('chat.tools.web_search.allowed_domains_mode', 'global');
    config()->set('chat.tools.web_search.allowed_domains', ['laravel.com', 'php.net']);
    config()->set('chat.tools.web_search.use_city_location', true);
    config()->set('chat.tools.web_search.location_region', 'NY');
    config()->set('chat.tools.web_search.default_country', 'US');

    $city = new City;
    $city->name = 'New York';

    $source = new ChatSource;
    $source->id = 1;
    $source->source_url = 'https://example.com/updates';

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        'What is the latest update today?'
    ));

    $webSearch = $tools->first(fn ($tool): bool => $tool instanceof WebSearch);
    $hasSimilaritySearch = $tools->contains(fn ($tool): bool => $tool instanceof SimilaritySearch);

    expect($hasSimilaritySearch)->toBeTrue();
    expect($webSearch)->toBeInstanceOf(WebSearch::class)
        ->and($webSearch->maxSearches)->toBe(5)
        ->and($webSearch->allowedDomains)->toBe(['laravel.com', 'php.net'])
        ->and($webSearch->city)->toBe('New York')
        ->and($webSearch->region)->toBe('NY')
        ->and($webSearch->country)->toBe('US');
});

it('does not add web search when fresh intent is required and the question is not fresh', function () {
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);
    config()->set('chat.tools.web_search.only_when_fresh_intent', true);

    $city = new City;
    $city->name = 'Wichita';

    $source = new ChatSource;
    $source->id = 2;
    $source->source_url = 'https://example.com/recycling';

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        'When is trash pickup?'
    ));

    $hasSimilaritySearch = $tools->contains(fn ($tool): bool => $tool instanceof SimilaritySearch);
    $hasWebSearch = $tools->contains(fn ($tool): bool => $tool instanceof WebSearch);

    expect($hasSimilaritySearch)->toBeTrue();
    expect($hasWebSearch)->toBeFalse();
});

it('adds web search for evergreen procedural questions when local evidence is weak', function () {
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);
    config()->set('chat.tools.web_search.only_when_fresh_intent', true);
    config()->set('chat.tools.web_search.allowed_domains', ['mabcd.com']);

    $city = new City;
    $city->name = 'Wichita';

    $source = new ChatSource;
    $source->id = 2;
    $source->source_url = 'https://www.wichita.gov/958/Licenses-Permits';

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$source]),
        'How do I get a demolition permit?',
        [],
        [[
            'title' => 'Frequently Asked Questions',
            'source_url' => 'https://www.wichita.gov/m/faq',
            'snippet' => 'FAQ. All content. Boards and committees.',
            'score' => 1.0,
        ]],
    ));

    $webSearch = $tools->first(fn ($tool): bool => $tool instanceof WebSearch);

    expect($webSearch)->toBeInstanceOf(WebSearch::class)
        ->and(collect($webSearch->allowedDomains)->sort()->values()->all())->toBe([
            'mabcd.com',
            'wichita.gov',
        ]);
});

it('adds web search when local procedural evidence is off target for the question focus', function () {
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);
    config()->set('chat.tools.web_search.only_when_fresh_intent', true);

    $city = new City;
    $city->name = 'Wichita';

    $sourceA = new ChatSource;
    $sourceA->id = 6;
    $sourceA->source_url = 'https://www.wichita.gov/958/Licenses-Permits';

    $sourceB = new ChatSource;
    $sourceB->id = 8;
    $sourceB->source_url = 'https://www.sedgwickcounty.org/how-do-i/';

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$sourceA, $sourceB]),
        'How do I get a demolition permit?',
        [],
        [[
            'title' => 'Burn Permits | Sedgwick County, Kansas',
            'source_url' => 'https://www.sedgwickcounty.org/fire/forms/burn-permits/',
            'snippet' => 'Open burns and burn permit requirements.',
            'score' => 48.0,
        ], [
            'title' => 'Traffic, Street & Construction Permitting | Wichita, KS',
            'source_url' => 'https://www.wichita.gov/1311/Traffic-Street-Construction-Permitting',
            'snippet' => 'Right-of-way and street construction permits.',
            'score' => 44.0,
        ]],
    ));

    $webSearch = $tools->first(fn ($tool): bool => $tool instanceof WebSearch);

    expect($webSearch)->toBeInstanceOf(WebSearch::class)
        ->and(collect($webSearch->allowedDomains)->sort()->values()->all())->toBe([
            'sedgwickcounty.org',
            'wichita.gov',
        ]);
});

it('adds web search when only low-ranked procedural evidence matches the permit focus', function () {
    config()->set('chat.retrieval_mode', 'local_then_web');
    config()->set('chat.tools.similarity.enabled', true);
    config()->set('chat.tools.web_search.enabled', true);
    config()->set('chat.tools.web_search.only_when_fresh_intent', true);

    $city = new City;
    $city->name = 'Wichita';

    $sourceA = new ChatSource;
    $sourceA->id = 6;
    $sourceA->source_url = 'https://www.wichita.gov/958/Licenses-Permits';

    $sourceB = new ChatSource;
    $sourceB->id = 8;
    $sourceB->source_url = 'https://www.sedgwickcounty.org/how-do-i/';

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'buildTools');
    $method->setAccessible(true);

    $tools = collect($method->invoke(
        app(AnswerSynthesizer::class),
        $city,
        collect([$sourceA, $sourceB]),
        'How do I get a demolition permit?',
        [],
        [[
            'title' => 'Burn Permits | Sedgwick County, Kansas',
            'source_url' => 'https://www.sedgwickcounty.org/fire/forms/burn-permits/',
            'snippet' => 'Open burns and burn permit requirements.',
            'score' => 48.0,
        ], [
            'title' => 'Traffic, Street & Construction Permitting | Wichita, KS',
            'source_url' => 'https://www.wichita.gov/1311/Traffic-Street-Construction-Permitting',
            'snippet' => 'Right-of-way and street construction permits.',
            'score' => 44.0,
        ], [
            'title' => 'Apply For (Wichita)',
            'source_url' => 'https://www.wichita.gov/DocumentCenter/View/17596',
            'snippet' => 'Demolition permit applications may require additional review.',
            'score' => 20.0,
        ]],
    ));

    $webSearch = $tools->first(fn ($tool): bool => $tool instanceof WebSearch);

    expect($webSearch)->toBeInstanceOf(WebSearch::class)
        ->and(collect($webSearch->allowedDomains)->sort()->values()->all())->toBe([
            'sedgwickcounty.org',
            'wichita.gov',
        ]);
});
