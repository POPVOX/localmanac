<?php

use App\Livewire\Admin\Scrapers\Form as ScraperForm;
use App\Models\City;
use App\Models\Scraper;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('initializes with an empty config string when creating a scraper', function () {
    $user = User::factory()->create();
    City::create(['name' => 'Test City', 'slug' => 'test-city']);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->assertSet('config', '');
});

it('pretty prints stored scraper config when editing', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Pretty City', 'slug' => 'pretty-city']);

    $config = [
        'profile' => 'generic_listing',
        'list' => ['link_selector' => 'article a'],
    ];

    $scraperId = DB::table('scrapers')->insertGetId([
        'city_id' => $city->id,
        'name' => 'Encoded Scraper',
        'slug' => 'encoded-scraper',
        'type' => 'rss',
        'source_url' => 'https://example.com/feed',
        'config' => json_encode(json_encode($config)),
        'is_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $expected = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    Livewire::actingAs($user)->test(ScraperForm::class, ['scraper' => Scraper::findOrFail($scraperId)])
        ->assertSet('config', $expected);
});

it('validates config JSON before saving', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Invalid City', 'slug' => 'invalid-city']);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('name', 'Broken Scraper')
        ->set('slug', 'broken-scraper')
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('frequency', 'hourly')
        ->set('config', '{invalid')
        ->call('save')
        ->assertHasErrors(['config']);

    expect(Scraper::count())->toBe(0);
});

it('stores a valid JSON config as an array', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Valid City', 'slug' => 'valid-city']);

    $configJson = '{"profile":"generic_listing","list":{"link_selector":"article a"}}';

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $component = Livewire::actingAs($user)->test(ScraperForm::class);

    $component
        ->set('name', 'Valid Scraper')
        ->set('slug', 'valid-scraper')
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('frequency', 'daily')
        ->set('runAt', '08:30')
        ->set('config', $configJson)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.scrapers.index'));

    expect(Scraper::count())->toBe(1);

    $scraper = Scraper::first();

    expect($scraper)->not->toBeNull()
        ->and(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'insert into "scrapers"')))->toBeTrue()
        ->and($component->get('scraper')?->is($scraper))->toBeTrue()
        ->and($scraper?->source_url)->toBe('https://example.com/feed')
        ->and($scraper?->frequency)->toBe('daily')
        ->and($scraper?->run_at)->toBe('08:30')
        ->and($scraper?->config)->toBe([
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => 'article a',
            ],
        ]);
});

it('defaults run at time when left blank', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Default City', 'slug' => 'default-city']);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('name', 'Default Time Scraper')
        ->set('slug', 'default-time-scraper')
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://example.com/default-time')
        ->set('frequency', 'daily')
        ->set('runAt', '')
        ->call('save')
        ->assertHasNoErrors();

    $scraper = Scraper::first();

    expect($scraper)->not->toBeNull()
        ->and($scraper?->run_at)->toBe(Scraper::DEFAULT_RUN_AT);
});

it('clears stray config when resetConfigField is invoked for new scrapers', function () {
    $user = User::factory()->create();
    City::create(['name' => 'Reset City', 'slug' => 'reset-city']);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('config', '{}')
        ->call('resetConfigField')
        ->assertSet('config', '');
});

it('template buttons do not inject organization id', function () {
    $user = User::factory()->create();
    City::create(['name' => 'Template City', 'slug' => 'template-city']);

    $genericExpected = json_encode([
        'profile' => 'generic_listing',
        'list' => [
            'link_selector' => 'article a',
            'link_attr' => 'href',
            'max_links' => 25,
        ],
        'article' => [
            'content_selector' => 'article',
            'remove_selectors' => ['script', 'style', 'nav', 'footer'],
        ],
        'best_effort' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $wichitaExpected = json_encode([
        'profile' => 'wichita_archive_pdf_list',
        'list' => [
            'href_contains' => 'Archive.aspx?ADID=',
            'max_links' => 50,
        ],
        'pdf' => [
            'extract' => true,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->call('applyTemplate', 'generic_listing')
        ->assertSet('config', $genericExpected)
        ->call('applyTemplate', 'wichita_archive_pdf_list')
        ->assertSet('config', $wichitaExpected);
});
