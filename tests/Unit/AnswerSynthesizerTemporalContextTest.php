<?php

use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('anchors structured and streaming prompts with explicit local time context', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-03 09:30:00', 'America/Chicago'));

    try {
        $city = new City;
        $city->name = 'Wichita';
        $city->timezone = 'America/Chicago';

        $synthesizer = app(AnswerSynthesizer::class);

        $structuredMethod = new ReflectionMethod(AnswerSynthesizer::class, 'structuredPrompt');
        $structuredMethod->setAccessible(true);

        $streamingMethod = new ReflectionMethod(AnswerSynthesizer::class, 'streamingPrompt');
        $streamingMethod->setAccessible(true);

        $structuredPrompt = $structuredMethod->invoke(
            $synthesizer,
            'What changed today?',
            $city,
            [],
            [],
        );

        $streamingPrompt = $streamingMethod->invoke(
            $synthesizer,
            'What changed today?',
            $city,
            [],
            [],
        );

        foreach ([$structuredPrompt, $streamingPrompt] as $prompt) {
            expect($prompt)
                ->toContain('Time context:')
                ->toContain('City timezone: America/Chicago')
                ->toContain('Current local datetime: 2026-03-03T09:30:00-06:00')
                ->toContain('Current local date: 2026-03-03 (Tuesday)')
                ->toContain('Interpret relative date phrases');
        }
    } finally {
        Carbon::setTestNow();
    }
});

it('adds procedural step guidance for civic how-to prompts when evidence is weak', function () {
    $city = new City;
    $city->name = 'Wichita';
    $city->timezone = 'America/Chicago';

    $synthesizer = app(AnswerSynthesizer::class);

    $structuredMethod = new ReflectionMethod(AnswerSynthesizer::class, 'structuredPrompt');
    $structuredMethod->setAccessible(true);

    $prompt = $structuredMethod->invoke(
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
        ->toContain('This is a civic how-to or permit-style question.')
        ->toContain('answer with a short ordered step-by-step list')
        ->toContain('Use official-domain web search if needed to find the most specific procedural page.');
});
