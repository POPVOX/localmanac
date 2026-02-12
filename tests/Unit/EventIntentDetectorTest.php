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

it('does not flag weak temporal-only civic asks as event intent', function () {
    $detector = new EventIntentDetector;

    expect($detector->isEventIntent('Is city hall open this week?'))->toBeFalse();
});
