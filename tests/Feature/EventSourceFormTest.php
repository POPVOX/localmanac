<?php

use App\Livewire\Admin\EventSources\Form as EventSourceForm;
use App\Models\City;
use App\Models\EventSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('initializes with an empty config string when creating an event source', function () {
    $user = User::factory()->create();
    City::create([
        'name' => 'Test City',
        'slug' => 'test-city',
        'state' => 'CO',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->assertSet('config', '');
});

it('pretty prints stored event source config when editing', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Pretty City',
        'slug' => 'pretty-city',
        'state' => 'TX',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    $config = [
        'profile' => 'visit_wichita_simpleview',
        'json' => ['root_path' => 'docs.docs'],
    ];

    $sourceId = DB::table('event_sources')->insertGetId([
        'city_id' => $city->id,
        'name' => 'Encoded Source',
        'source_type' => 'json_api',
        'source_url' => 'https://example.com/feed',
        'config' => json_encode(json_encode($config)),
        'frequency' => 'daily',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $expected = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    Livewire::actingAs($user)->test(EventSourceForm::class, ['source' => EventSource::findOrFail($sourceId)])
        ->assertSet('config', $expected);
});

it('validates config JSON before saving', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Invalid City',
        'slug' => 'invalid-city',
        'state' => 'MO',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->set('name', 'Broken Source')
        ->set('cityId', $city->id)
        ->set('sourceType', 'rss')
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('config', '{invalid')
        ->call('save')
        ->assertHasErrors(['config']);

    expect(EventSource::count())->toBe(0);
});

it('requires ingestion configuration before an html source can be activated', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Pilot City',
        'slug' => 'pilot-city',
        'timezone' => 'America/Chicago',
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->set('name', 'Unconfigured Calendar')
        ->set('cityId', $city->id)
        ->set('sourceType', 'html')
        ->set('sourceUrl', 'https://example.com/events')
        ->set('isActive', true)
        ->set('config', '')
        ->call('save')
        ->assertHasErrors(['config']);

    expect(EventSource::count())->toBe(0);
});

it('allows incomplete event sources to be saved as inactive drafts', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Draft City',
        'slug' => 'draft-city',
        'timezone' => 'America/Chicago',
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->set('name', 'Draft Calendar')
        ->set('cityId', $city->id)
        ->set('sourceType', 'html')
        ->set('sourceUrl', 'https://example.com/events')
        ->set('isActive', false)
        ->set('config', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.event-sources.index'));

    expect(EventSource::first()?->is_active)->toBeFalse();
});

it('stores a valid JSON config as an array', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Valid City',
        'slug' => 'valid-city',
        'state' => 'OK',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    $configJson = '{"profile":"visit_wichita_simpleview","json":{"root_path":"docs.docs"}}';

    $component = Livewire::actingAs($user)->test(EventSourceForm::class);

    $component
        ->set('name', 'Valid Source')
        ->set('cityId', $city->id)
        ->set('sourceType', 'json_api')
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('config', $configJson)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.event-sources.index'));

    $source = EventSource::first();

    expect($source)->not->toBeNull()
        ->and($component->get('source')?->is($source))->toBeTrue()
        ->and($source?->source_url)->toBe('https://example.com/feed')
        ->and($source?->config)->toBe([
            'profile' => 'visit_wichita_simpleview',
            'json' => [
                'root_path' => 'docs.docs',
            ],
        ]);
});

it('accepts url templates with placeholders', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Template URL City',
        'slug' => 'template-url-city',
        'state' => 'OK',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->set('name', 'Template URL Source')
        ->set('cityId', $city->id)
        ->set('sourceType', 'json_api')
        ->set('sourceUrl', 'https://www.century2.com/events/calendar/{year}/{month}')
        ->set('config', '{"root_path":""}')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.event-sources.index'));

    $source = EventSource::query()->where('name', 'Template URL Source')->first();

    expect($source)->not->toBeNull()
        ->and($source?->source_url)->toBe('https://www.century2.com/events/calendar/{year}/{month}');
});

it('clears stray config when resetConfigField is invoked for new sources', function () {
    $user = User::factory()->create();
    City::create([
        'name' => 'Reset City',
        'slug' => 'reset-city',
        'state' => 'KS',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->set('config', '{}')
        ->call('resetConfigField')
        ->assertSet('config', '');
});

it('template buttons set the expected config', function () {
    $user = User::factory()->create();
    City::create([
        'name' => 'Template City',
        'slug' => 'template-city',
        'state' => 'NE',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    $visitWichita = json_encode([
        'profile' => 'visit_wichita_simpleview',
        'json' => [
            'root_path' => 'docs.docs',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $libcal = json_encode([
        'profile' => 'wichita_libnet_libcal',
        'json' => [
            'root_path' => '',
            'days' => 43,
            'req' => [
                'private' => false,
                'locations' => [],
                'ages' => [],
                'types' => [],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->call('applyTemplate', 'visit_wichita')
        ->assertSet('config', $visitWichita)
        ->call('applyTemplate', 'libcal')
        ->assertSet('config', $libcal);
});

it('previews event extraction before saving', function () {
    $user = User::factory()->create();
    $city = City::factory()->create(['timezone' => 'America/Chicago']);
    $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:form-preview\r\nSUMMARY:City Council\r\nDTSTART:20260910T180000\r\nLOCATION:City Hall\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    Http::fake([
        'https://example.gov/calendar.ics' => Http::response($ics, 200),
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class)
        ->set('cityId', $city->id)
        ->set('name', 'City Calendar')
        ->set('sourceType', 'ics')
        ->set('sourceUrl', 'https://example.gov/calendar.ics')
        ->set('config', '{"timezone":"America/Chicago"}')
        ->call('previewSource')
        ->assertSet('previewValid', true)
        ->assertSee('City Council');
});

it('clears stale health repair state when an event source is edited', function () {
    $user = User::factory()->create();
    $source = EventSource::factory()->create([
        'source_type' => 'ics',
        'source_url' => 'https://example.gov/calendar.ics',
        'config' => ['timezone' => null],
        'health_status' => 'unhealthy',
        'health_checked_at' => now(),
        'health_error' => 'Old extraction failed.',
        'repair_proposal' => ['kind' => 'event', 'type' => 'ics'],
    ]);

    Livewire::actingAs($user)->test(EventSourceForm::class, ['source' => $source])
        ->set('name', 'Updated calendar')
        ->call('save')
        ->assertHasNoErrors();

    $source->refresh();

    expect($source->health_status)->toBe('unknown')
        ->and($source->health_checked_at)->toBeNull()
        ->and($source->health_error)->toBeNull()
        ->and($source->repair_proposal)->toBeNull();
});
