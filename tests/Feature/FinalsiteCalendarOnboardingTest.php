<?php

use App\Livewire\Admin\Sources\Wizard;
use App\Models\City;
use App\Models\EventSource;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('saves a Finalsite school calendar at 82 percent confidence and explains invalidated previews', function () {
    Http::preventStrayRequests();
    config()->set('scraper-assistant.ai.refine_enabled', false);
    $url = 'https://schools.example/';
    $html = '<title>School</title><section class="fsCalendar fsList">';
    foreach ([20, 21] as $day) {
        $html .= '<article><div class="fsTitle"><a class="fsCalendarEventLink" href="#">School meeting '.$day.'</a></div>'
            .'<time datetime="2026-10-'.$day.'T18:30:00-04:00">Oct '.$day.'</time></article>';
    }
    Http::fake([$url => Http::response($html.'</section>', 200)]);

    $wizard = Livewire::actingAs(User::factory()->create())->test(Wizard::class)
        ->set('cityId', City::factory()->create(['timezone' => 'America/New_York'])->id)
        ->set('sourcePurpose', 'event')
        ->set('sourceUrl', $url)
        ->call('analyze')
        ->assertSet('confidence', 0.82)
        ->assertSet('previewValid', true)
        ->assertSet('previewItems.0.title', 'School meeting 20');

    $wizard->set('rawConfig', $wizard->get('rawConfig')."\n")
        ->assertSet('previewValid', false)
        ->assertSet('previewItems', [])
        ->assertSee('Source settings changed.')
        ->call('save')->assertHasErrors('sourceUrl');
    expect(EventSource::count())->toBe(0);

    $wizard->call('preview')
        ->assertSet('previewValid', true)
        ->call('save')->assertHasNoErrors()->assertRedirect();
    expect(EventSource::query()->sole()->source_url)->toBe($url);
});
