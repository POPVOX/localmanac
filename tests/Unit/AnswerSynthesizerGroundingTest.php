<?php

use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('grounds possessives within civic organization names without accepting invented offices', function (string $answer, bool $grounded) {
    $method = new ReflectionMethod(AnswerSynthesizer::class, 'isAnswerGrounded');

    expect($method->invoke(app(AnswerSynthesizer::class),
        "How can I contact Stamford's Board of Representatives?",
        $answer,
        new City(['name' => 'Stamford']),
        [[
            'title' => 'Stamford Board of Representatives',
            'source_url' => 'https://boardofreps.org/home.aspx',
            'snippet' => 'Contact the Stamford Board of Representatives at 203-977-4024. Meeting agendas are on its website. The Children\'s Advisory Council also maintains public records.',
        ]], [],
    ))->toBe($grounded);
})->with([
    'production city possessive' => ["To contact Stamford's Board of Representatives, visit https://boardofreps.org/home.aspx.", true],
    'curly city possessive' => ['Contact Stamford’s Board of Representatives at 203-977-4024.', true],
    'possessive in official name' => ["Contact the Children's Advisory Council.", true],
    'unsupported office' => ["Contact Stamford's Fictional Office.", false],
    'unsupported office with curly apostrophe' => ['Contact Stamford’s Fictional Office.', false],
    'unsupported phone' => ["Contact Stamford's Board of Representatives at 203-555-0199.", false],
    'unsupported URL' => ["Contact Stamford's Board of Representatives at https://invented.example/.", false],
]);

it('grounds referred entities within sentence and paragraph boundaries', function (string $answer, bool $grounded) {
    $synthesizer = app(AnswerSynthesizer::class);
    $method = new ReflectionMethod(AnswerSynthesizer::class, 'isAnswerGrounded');
    $evidence = [[
        'title' => 'Building permit applications',
        'source_url' => 'https://www.houstonpermittingcenter.org/building-code-enforcement/permits',
        'snippet' => 'Submit building permit plans via ProjectDox. Contact the Houston Permitting Center for help. The U.S. Department of Energy provides energy guidance.',
    ]];

    expect($method->invoke(
        $synthesizer,
        'How do I get a building permit in Houston?',
        $answer,
        new City(['name' => 'Houston']),
        $evidence,
        [],
    ))->toBe($grounded);
})->with([
    'production paragraph break' => ["Submit your plans via ProjectDox.\n\nFor assistance, contact the Houston Permitting Center.", true],
    'sentence on the same line' => ['Submit your plans via ProjectDox. For assistance, contact the Houston Permitting Center.', true],
    'line break without punctuation' => ["Submit plans via ProjectDox\nFor assistance, contact the Houston Permitting Center.", true],
    'abbreviated organization' => ['Contact the U.S. Department of Energy for energy guidance.', true],
    'unsupported organization after a sentence' => ['Submit plans via ProjectDox. Contact the Fictional Permit Agency.', false],
    'unsupported organization after a paragraph' => ["Submit plans via ProjectDox.\n\nContact the Fictional Permit Agency.", false],
    'unsupported URL remains rejected' => ['Apply via ProjectDox at https://invented.example/apply.', false],
    'possessive organization name' => ["Submit plans via ProjectDox.\n\nFor more information, visit the Houston Permitting Center's website.", true],
    'curly possessive organization name' => ['Visit the Houston Permitting Center’s website.', true],
    'unsupported possessive organization' => ["Visit the Fictional Permit Agency's website.", false],
]);

it('requires older edition fees to name their date and disclose that the current fee is unverified', function (string $answer, bool $grounded) {
    Carbon::setTestNow('2026-10-11 12:00:00');

    try {
        $method = new ReflectionMethod(AnswerSynthesizer::class, 'isAnswerGrounded');
        expect($method->invoke(app(AnswerSynthesizer::class), 'What is the demolition permit fee?', $answer,
            new City(['name' => 'Jackson', 'timezone' => 'America/Chicago']), [[
                'title' => 'Jackson Construction Guide (January 2022 edition)',
                'snippet' => 'Residential building demolition permit fee: $50.00.',
            ]], []))->toBe($grounded);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'production failure' => ['You will need to pay a fee of $50.00 for a residential building demolition. The application steps are not verified.', false],
    'date without uncertainty' => ['The 2022 guide lists a $50 residential demolition fee.', false],
    'uncertainty without edition' => ['The guide lists $50; I have not verified the current fee.', false],
    'dated and qualified' => ['The January 2022 guide lists $50; I have not verified the current fee.', true],
    'reverse qualification' => ['The 2022 edition lists $50.00. The current demolition permit fee has not been confirmed.', true],
    'unrelated caveat' => ['The 2022 guide lists $50. The current application process is unverified.', false],
    'no-answer phrase cannot bypass check' => ['The fee is $50.00. I could not find the application steps.', false],
    'different amount is not supported' => ['The 2022 guide lists $5; I have not verified the current fee.', false],
]);

it('does not treat current or undated support as an older edition', function (string $title) {
    Carbon::setTestNow('2026-10-11 12:00:00');

    try {
        $method = new ReflectionMethod(AnswerSynthesizer::class, 'isAnswerGrounded');
        expect($method->invoke(app(AnswerSynthesizer::class), 'What is the fee?', 'The fee is $50.00.',
            new City(['name' => 'Jackson', 'timezone' => 'America/Chicago']), [[
                'title' => $title,
                'snippet' => 'The fee is $50.',
            ]], []))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
})->with(['2026 edition', 'Fee schedule', 'History of the 2022 festival']);

it('allows a fee corroborated by a current edition', function () {
    Carbon::setTestNow('2026-10-11 12:00:00');

    try {
        $method = new ReflectionMethod(AnswerSynthesizer::class, 'isAnswerGrounded');
        expect($method->invoke(app(AnswerSynthesizer::class), 'What is the fee?', 'The fee is $50.',
            new City(['name' => 'Jackson']), [
                ['title' => '2022 edition', 'snippet' => 'The fee is $50.00.'],
                ['title' => 'Revised January 2026', 'snippet' => 'The fee is $50.'],
            ], []))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});
