<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Services\Chat\ChatSourceSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('selects sources by question relevance and priority fallback', function () {
    config()->set('scout.driver', 'collection');

    $city = City::factory()->create();
    $otherCity = City::factory()->create();

    $relevant = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Recycling & Trash',
        'tags' => ['recycling', 'trash'],
        'priority' => 1,
    ]);

    $fallback = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Government',
        'tags' => ['government'],
        'priority' => 10,
    ]);

    ChatSource::factory()->create([
        'city_id' => $otherCity->id,
        'name' => 'Other City',
        'priority' => 10,
    ]);

    $selector = app(ChatSourceSelector::class);

    $results = $selector->select($city->id, 'trash pickup', 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id'))->toContain($relevant->id)
        ->and($results->pluck('id'))->toContain($fallback->id);
});
