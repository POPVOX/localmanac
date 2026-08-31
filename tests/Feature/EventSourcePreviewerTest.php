<?php

use App\Models\City;
use App\Services\Ingestion\Assistant\EventSourcePreviewer;
use Illuminate\Support\Facades\Http;

it('previews event sources without creating ingestion records', function () {
    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:preview-1\r\nSUMMARY:Planning Commission\r\nDTSTART:20260910T180000\r\nLOCATION:City Hall\r\nURL:https://example.gov/events/planning\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    Http::fake([
        'https://example.gov/calendar.ics' => Http::response($ics, 200),
    ]);

    $preview = app(EventSourcePreviewer::class)->preview(
        cityId: $city->id,
        type: 'ics',
        sourceUrl: 'https://example.gov/calendar.ics',
        config: ['timezone' => 'America/Chicago'],
    );

    expect($preview['valid'])->toBeTrue()
        ->and($preview['items'])->toHaveCount(1)
        ->and($preview['items'][0]['title'])->toBe('Planning Commission')
        ->and($preview['items'][0]['location'])->toBe('City Hall')
        ->and(\App\Models\EventSource::count())->toBe(0);
});
