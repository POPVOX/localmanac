<?php

use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventNormalizer;
use App\Services\Ingestion\Fetchers\HtmlProfiles\GenericHtmlListProfile;
use App\Services\Ingestion\Fetchers\HtmlProfiles\HtmlProfileRegistry;
use App\Services\Ingestion\Fetchers\HtmlProfiles\WichitaChamberEventsProfile;
use App\Services\Ingestion\Fetchers\JsonProfiles\GenericJsonProfile;
use App\Services\Ingestion\Fetchers\JsonProfiles\JsonProfileRegistry;
use App\Services\Ingestion\Fetchers\JsonProfiles\VisitWichitaSimpleviewProfile;

it('resolves json profiles by name and falls back to generic', function () {
    $dateParser = new CalendarDateParser;
    $normalizer = new EventNormalizer;

    $registry = new JsonProfileRegistry(
        [
            new VisitWichitaSimpleviewProfile($dateParser, $normalizer),
        ],
        new GenericJsonProfile($dateParser, $normalizer),
    );

    expect($registry->resolve('visit_wichita_simpleview'))
        ->toBeInstanceOf(VisitWichitaSimpleviewProfile::class)
        ->and($registry->resolve('unknown_profile'))
        ->toBeInstanceOf(GenericJsonProfile::class);
});

it('resolves html profiles by name and falls back to generic', function () {
    $dateParser = new CalendarDateParser;
    $normalizer = new EventNormalizer;

    $registry = new HtmlProfileRegistry(
        [
            new WichitaChamberEventsProfile($dateParser, $normalizer),
        ],
        new GenericHtmlListProfile($dateParser, $normalizer),
    );

    expect($registry->resolve('wichita_chamber_events'))
        ->toBeInstanceOf(WichitaChamberEventsProfile::class)
        ->and($registry->resolve('unknown_profile'))
        ->toBeInstanceOf(GenericHtmlListProfile::class);
});
