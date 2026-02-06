<?php

use App\Models\City;
use App\Models\IssueArea;

it('syncs shared issue areas for all cities', function () {
    config()->set('issue-areas.shared', [
        ['name' => 'Government', 'slug' => 'government'],
        ['name' => 'Budget', 'slug' => 'budget'],
    ]);

    $cityA = City::create([
        'name' => 'Alpha City',
        'slug' => 'alpha-city',
    ]);

    $cityB = City::create([
        'name' => 'Beta City',
        'slug' => 'beta-city',
    ]);

    $this->artisan('issue-areas:sync')->assertExitCode(0);

    expect(IssueArea::count())->toBe(4)
        ->and(IssueArea::where('city_id', $cityA->id)->pluck('slug')->sort()->values()->all())
        ->toBe(['budget', 'government'])
        ->and(IssueArea::where('city_id', $cityB->id)->pluck('slug')->sort()->values()->all())
        ->toBe(['budget', 'government']);
});
