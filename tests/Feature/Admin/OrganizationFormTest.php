<?php

use App\Livewire\Admin\Organizations\Form;
use App\Models\City;
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
