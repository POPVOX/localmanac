<?php

use App\Livewire\Admin\Scrapers\Form as ScraperForm;
use App\Models\City;
use App\Models\Scraper;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('initializes with assistant mode and restricted raw config for non super admins', function () {
    $user = User::factory()->create();
    City::create(['name' => 'Test City', 'slug' => 'test-city']);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->assertSet('isSuperAdmin', false)
        ->assertSet('assistantInputMode', 'url')
        ->assertSee('Raw JSON editing is restricted');
});

it('pretty prints stored scraper config when editing as super admin', function () {
    $user = User::factory()->superAdmin()->create();
    $city = City::create(['name' => 'Pretty City', 'slug' => 'pretty-city']);

    $config = [
        'profile' => 'generic_listing',
        'list' => ['link_selector' => 'article a'],
    ];

    $scraperId = DB::table('scrapers')->insertGetId([
        'city_id' => $city->id,
        'name' => 'Encoded Scraper',
        'slug' => 'encoded-scraper',
        'type' => 'html',
        'source_url' => 'https://example.com/feed',
        'config' => json_encode(json_encode($config)),
        'is_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $expected = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    Livewire::actingAs($user)->test(ScraperForm::class, ['scraper' => Scraper::findOrFail($scraperId)])
        ->assertSet('isSuperAdmin', true)
        ->assertSet('config', $expected)
        ->assertSee('Hide raw JSON');
});

it('blocks non super admin save until preview is valid', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Preview City', 'slug' => 'preview-city']);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('name', 'No Preview Scraper')
        ->set('slug', 'no-preview-scraper')
        ->set('cityId', $city->id)
        ->set('type', 'html')
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('frequency', 'daily')
        ->call('save')
        ->assertHasErrors(['config']);

    expect(Scraper::count())->toBe(0);
});

it('ignores non super admin raw config tampering and saves assistant draft after preview', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Valid City', 'slug' => 'valid-city']);

    $draft = [
        'profile' => 'generic_listing',
        'list' => [
            'link_selector' => 'article a',
            'link_attr' => 'href',
            'max_links' => 25,
        ],
        'article' => [
            'content_selector' => 'article',
            'remove_selectors' => ['script', 'style'],
        ],
        'best_effort' => true,
    ];

    $hash = hash('sha256', json_encode($draft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('name', 'Draft Scraper')
        ->set('slug', 'draft-scraper')
        ->set('cityId', $city->id)
        ->set('type', 'html')
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('frequency', 'daily')
        ->set('config', '{"profile":"wichita_archive_pdf_list"}')
        ->set('assistantHasDraft', true)
        ->set('assistantDraftConfig', $draft)
        ->set('assistantPreviewValid', true)
        ->set('assistantPreviewHash', $hash)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.scrapers.index'));

    $scraper = Scraper::first();

    expect($scraper)->not->toBeNull()
        ->and($scraper?->config)->toBe($draft);
});

it('rejects save when non super admin draft changed after preview', function () {
    $user = User::factory()->create();
    $city = City::create(['name' => 'Stale Preview City', 'slug' => 'stale-preview-city']);

    $draft = [
        'profile' => 'generic_listing',
        'list' => ['link_selector' => 'article a', 'link_attr' => 'href', 'max_links' => 25],
        'article' => ['content_selector' => 'article', 'remove_selectors' => ['script']],
        'best_effort' => true,
    ];

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('name', 'Stale Preview Scraper')
        ->set('slug', 'stale-preview-scraper')
        ->set('cityId', $city->id)
        ->set('type', 'html')
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('frequency', 'daily')
        ->set('assistantHasDraft', true)
        ->set('assistantDraftConfig', $draft)
        ->set('assistantPreviewValid', true)
        ->set('assistantPreviewHash', 'stale-hash')
        ->call('save')
        ->assertHasErrors(['config']);

    expect(Scraper::count())->toBe(0);
});

it('validates raw config JSON for super admins', function () {
    $user = User::factory()->superAdmin()->create();
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

it('stores super admin raw JSON config as array', function () {
    $user = User::factory()->superAdmin()->create();
    $city = City::create(['name' => 'Super City', 'slug' => 'super-city']);

    $configJson = '{"profile":"generic_listing","list":{"link_selector":"article a"}}';

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('name', 'Super Scraper')
        ->set('slug', 'super-scraper')
        ->set('cityId', $city->id)
        ->set('type', 'html')
        ->set('sourceUrl', 'https://example.com/feed')
        ->set('frequency', 'daily')
        ->set('runAt', '08:30')
        ->set('config', $configJson)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.scrapers.index'));

    $scraper = Scraper::first();

    expect($scraper)->not->toBeNull()
        ->and($scraper?->run_at)->toBe('08:30')
        ->and($scraper?->config)->toBe([
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => 'article a',
            ],
        ]);
});

it('defaults run at time when left blank', function () {
    $user = User::factory()->superAdmin()->create();
    $city = City::create(['name' => 'Default City', 'slug' => 'default-city']);

    Livewire::actingAs($user)->test(ScraperForm::class)
        ->set('name', 'Default Time Scraper')
        ->set('slug', 'default-time-scraper')
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://example.com/default-time')
        ->set('frequency', 'daily')
        ->set('runAt', '')
        ->set('config', '{}')
        ->call('save')
        ->assertHasNoErrors();

    $scraper = Scraper::first();

    expect($scraper)->not->toBeNull()
        ->and($scraper?->run_at)->toBe(Scraper::DEFAULT_RUN_AT);
});

it('template buttons do not inject organization id for super admins', function () {
    $user = User::factory()->superAdmin()->create();
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
