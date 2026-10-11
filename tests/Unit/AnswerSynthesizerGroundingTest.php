<?php

use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;

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
