<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\IssueArea;
use Livewire\Livewire;

it('renders a category select when issue areas exist', function () {
    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Budget & Taxes',
        'slug' => 'budget-taxes',
    ]);

    IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Public Safety',
        'slug' => 'public-safety',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('All')
        ->assertSee('Budget & Taxes')
        ->assertSee('Public Safety');
});
