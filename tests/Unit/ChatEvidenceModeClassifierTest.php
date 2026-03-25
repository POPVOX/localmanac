<?php

use App\Services\Chat\ChatEvidenceModeClassifier;

it('classifies reference, events, and updates questions using lightweight heuristics', function () {
    $classifier = app(ChatEvidenceModeClassifier::class);

    expect($classifier->classify('How do I get a demolition permit?'))->toBe(ChatEvidenceModeClassifier::REFERENCE)
        ->and($classifier->classify('What city council, board, and public meetings are coming up in Wichita in the next 14 days?'))->toBe(ChatEvidenceModeClassifier::EVENTS)
        ->and($classifier->classify('Summarize the most important local updates in Wichita from the last 7 days.'))->toBe(ChatEvidenceModeClassifier::UPDATES)
        ->and($classifier->classify('What’s new this week?'))->toBe(ChatEvidenceModeClassifier::UPDATES)
        ->and($classifier->classify('Whats new this week?'))->toBe(ChatEvidenceModeClassifier::UPDATES)
        ->and($classifier->classify('What active service alerts or disruptions should residents in Wichita know about right now? Focus on roads, utilities, water, trash, and public services.'))->toBe(ChatEvidenceModeClassifier::UPDATES);
});
