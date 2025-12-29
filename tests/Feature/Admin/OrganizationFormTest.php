<?php

use App\Livewire\Admin\Organizations\Form;
use App\Models\City;
use App\Models\Organization;
use Livewire\Livewire;

it('syncs the slug with the name until manually edited', function () {
    $city = City::factory()->create();

    Livewire::test(Form::class, ['organization' => null])
        ->set('cityId', $city->id)
        ->set('name', 'City')
        ->assertSet('slug', 'city')
        ->set('name', 'City of Wichita')
        ->assertSet('slug', 'city-of-wichita')
        ->set('slug', 'custom-slug')
        ->set('name', 'Another Name')
        ->assertSet('slug', 'custom-slug')
        ->set('slug', '')
        ->set('name', 'Back Again')
        ->assertSet('slug', 'back-again');
});

it('validates slug uniqueness after normalization', function () {
    $city = City::factory()->create();

    Organization::create([
        'city_id' => $city->id,
        'name' => 'City of Wichita',
        'slug' => 'city-of-wichita',
        'type' => 'government',
    ]);

    Livewire::test(Form::class, ['organization' => null])
        ->set('cityId', $city->id)
        ->set('name', 'City of Wichita')
        ->set('slug', 'City of Wichita')
        ->set('type', 'government')
        ->set('website', 'https://wichita.gov')
        ->call('save')
        ->assertHasErrors(['slug' => 'unique']);
});
