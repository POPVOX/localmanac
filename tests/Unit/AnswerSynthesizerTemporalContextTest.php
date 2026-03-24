<?php

use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('anchors the streaming prompt with explicit local time context', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-03 09:30:00', 'America/Chicago'));

    try {
        $city = new City;
        $city->name = 'Wichita';
        $city->timezone = 'America/Chicago';

        $synthesizer = app(AnswerSynthesizer::class);

        $streamingMethod = new ReflectionMethod(AnswerSynthesizer::class, 'streamingPrompt');
        $streamingMethod->setAccessible(true);

        $streamingPrompt = $streamingMethod->invoke(
            $synthesizer,
            'What changed today?',
            $city,
            [],
            [],
        );

        expect($streamingPrompt)
            ->toContain('Time context:')
            ->toContain('City timezone: America/Chicago')
            ->toContain('Current local datetime: 2026-03-03T09:30:00-06:00')
            ->toContain('Current local date: 2026-03-03 (Tuesday)')
            ->toContain('Interpret relative date phrases');
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps the streaming prompt neutral for civic how-to prompts', function () {
    $city = new City;
    $city->name = 'Wichita';
    $city->timezone = 'America/Chicago';

    $synthesizer = app(AnswerSynthesizer::class);
    $streamingMethod = new ReflectionMethod(AnswerSynthesizer::class, 'streamingPrompt');
    $streamingMethod->setAccessible(true);

    $prompt = $streamingMethod->invoke(
        $synthesizer,
        'How do I get a demolition permit?',
        $city,
        [[
            'title' => 'Frequently Asked Questions',
            'source_url' => 'https://www.wichita.gov/m/faq',
            'snippet' => 'FAQ. All content. Boards and committees.',
            'score' => 1.0,
        ]],
        [],
    );

    expect($prompt)
        ->not->toContain('step-by-step')
        ->toContain('Do not name any department, agency, provider, company, office, or organization unless that exact name appears in retrieved evidence.')
        ->not->toContain('Use official-domain web search if needed to find the most specific procedural page.');
});
