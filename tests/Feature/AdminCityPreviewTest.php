<?php

use App\Models\City;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;

test('city previews are restricted to administrators and use slug urls', function () {
    $city = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
        'timezone' => 'America/Chicago',
    ]);

    $previewUrl = route('admin.cities.preview', $city);

    $this->get($previewUrl)->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get($previewUrl)
        ->assertForbidden();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get($previewUrl)
        ->assertOk()
        ->assertSee('Ask LocAlmanac About Lawrence')
        ->assertSee(route('admin.cities.calendar', $city), false);
});

test('city calendar previews keep the admin-only city context', function () {
    $city = City::factory()->create([
        'name' => 'Jackson',
        'slug' => 'jackson-tn',
        'timezone' => 'America/Chicago',
    ]);
    $date = Carbon::parse('2026-09-03 10:00:00', $city->timezone);

    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'Jackson Planning Meeting',
        'starts_at' => $date,
        'ends_at' => $date->copy()->addHour(),
    ]);

    $response = $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.cities.calendar', [
            'city' => $city,
            'date' => $date->toDateString(),
        ]));

    $response->assertOk()
        ->assertSee('Jackson Planning Meeting')
        ->assertSee(route('admin.cities.preview', $city), false)
        ->assertSee(route('admin.cities.calendar', [
            'city' => $city,
            'date' => $date->copy()->addDay()->toDateString(),
        ]), false);
});

test('the cities index includes a preview action for each city', function () {
    $city = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
    ]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.cities.index'))
        ->assertOk()
        ->assertSee('Preview')
        ->assertSee(route('admin.cities.preview', $city), false);
});
