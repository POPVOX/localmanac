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
