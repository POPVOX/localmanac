<?php

use App\Livewire\Admin\Cities\Form as CityForm;
use App\Models\City;
use App\Models\User;
use Livewire\Livewire;

it('updates a city', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Old City',
        'slug' => 'old-city',
        'state' => 'CO',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    Livewire::actingAs($user)->test(CityForm::class, ['city' => $city])
        ->set('name', 'New City')
        ->set('slug', 'new-city')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.cities.index'));

    $city->refresh();

    expect($city->slug)->toBe('new-city')
        ->and($city->name)->toBe('New City');
});

it('requires a timezone to be selected when creating a city', function () {
    Livewire::actingAs(User::factory()->create())->test(CityForm::class)
        ->assertSet('timezone', '')
        ->set('name', 'Stamford')
        ->set('slug', 'stamford')
        ->call('save')
        ->assertHasErrors(['timezone' => 'required']);

    expect(City::where('slug', 'stamford')->exists())->toBeFalse();
});

it('saves a new city with the selected timezone', function (string $timezone) {
    Livewire::actingAs(User::factory()->create())->test(CityForm::class)
        ->set('name', 'Stamford')
        ->set('slug', 'stamford')
        ->set('state', 'CT')
        ->set('timezone', $timezone)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.cities.index'));

    expect(City::where('slug', 'stamford')->firstOrFail()->timezone)->toBe($timezone);
})->with(['America/New_York', 'America/Chicago', 'Asia/Kolkata']);

it('can correct an existing city timezone without recreating it', function () {
    $city = City::factory()->create([
        'name' => 'Stamford',
        'slug' => 'stamford',
        'state' => 'CT',
        'timezone' => 'America/Chicago',
    ]);

    Livewire::actingAs(User::factory()->create())->test(CityForm::class, ['city' => $city])
        ->assertSet('timezone', 'America/Chicago')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasNoErrors();

    expect($city->fresh()->timezone)->toBe('America/New_York')
        ->and(City::where('slug', 'stamford')->count())->toBe(1);

    Livewire::test(CityForm::class, ['city' => $city->fresh()])
        ->assertSet('timezone', 'America/New_York');
});

it('rejects invalid timezones without changing the saved timezone', function () {
    $city = City::factory()->create(['timezone' => 'America/New_York']);

    Livewire::actingAs(User::factory()->create())->test(CityForm::class, ['city' => $city])
        ->set('timezone', 'Eastern')
        ->call('save')
        ->assertHasErrors(['timezone' => 'timezone']);

    expect($city->fresh()->timezone)->toBe('America/New_York');
});

it('offers Eastern Time and other IANA timezones in a select field', function () {
    $component = Livewire::actingAs(User::factory()->create())->test(CityForm::class);
    $crawler = new Symfony\Component\DomCrawler\Crawler($component->html());
    $select = $crawler->filter('select[wire\\:model="timezone"]');

    expect($select->count())->toBe(1)
        ->and($select->filter('option[value="America/New_York"]')->text())->toContain('Eastern Time')
        ->and($select->filter('option[value="Asia/Kolkata"]')->count())->toBe(1);
});
