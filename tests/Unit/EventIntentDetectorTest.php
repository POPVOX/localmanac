<?php

use App\Services\Chat\Event\EventIntentDetector;

it('detects event intent for event-focused asks', function () {
    $detector = new EventIntentDetector;

    expect($detector->isEventIntent("What's going on this weekend in the city?"))->toBeTrue();
});

it('does not flag civic-only asks as event intent', function () {
    $detector = new EventIntentDetector;

    expect($detector->isEventIntent('How much does a garage sale permit cost?'))->toBeFalse();
});

it('detects mixed civic and event asks', function () {
    $detector = new EventIntentDetector;

    expect($detector->isEventIntent("What's going on this weekend, and how much is downtown parking?"))->toBeTrue();
});

it('detects event intent for public meeting asks', function () {
    $detector = new EventIntentDetector;

    expect($detector->isEventIntent('What city council and board meetings are coming up in the next 14 days?'))->toBeTrue();
});

it('does not flag weak temporal-only civic asks as event intent', function () {
    $detector = new EventIntentDetector;

    expect($detector->isEventIntent('Is city hall open this week?'))->toBeFalse();
});

it('keeps civic contact and document lookups out of event listings', function (string $question) {
    expect((new EventIntentDetector)->isEventIntent($question))->toBeFalse();
})->with([
    "How can I contact Stamford's Board of Representatives, and where can I find its meeting agendas?",
    'What is the city council phone number?',
    'Where can I read the agenda for next week\'s council meeting?',
    'How do I download board meeting minutes?',
    'Where are commission meeting agendas posted?',
]);

it('preserves explicit meeting requests alongside reference questions', function (string $question) {
    expect((new EventIntentDetector)->isEventIntent($question))->toBeTrue();
})->with([
    'When does the city council meet, and how can I contact the office?',
    'Which board meetings are coming up, and where can I find the agendas?',
    'Show upcoming public meetings and their agendas.',
    'When is the next board meeting, and how can I speak at it?',
    'Which board meetings can I speak at?',
    'Show upcoming board meetings and their public comment rules.',
    'What board meetings are coming up, and how can I participate?',
]);

it('uses civic reference content for public participation instructions', function (string $question) {
    $detector = new EventIntentDetector;

    expect($detector->isEventIntent($question))->toBeFalse()
        ->and($detector->isCivicReferenceIntent($question))->toBeTrue();
})->with([
    'How can I speak at a Stamford Board of Education meeting?',
    'Where do I sign up to speak at the next council meeting?',
    'How do I submit public comment to the zoning board?',
    'Can I speak at a public hearing?',
    'What are the public-comment rules for board meetings?',
    'What is the time limit for speakers at council meetings?',
]);

it('keeps non-civic participation questions on the event path', function () {
    expect((new EventIntentDetector)->isEventIntent('How can I participate in the festival?'))->toBeTrue();
});
