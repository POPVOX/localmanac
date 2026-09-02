<?php

use App\Livewire\Admin\Dashboard;
use App\Models\Article;
use App\Models\ChatSource;
use App\Models\City;
use App\Models\CityAccessCode;
use App\Models\CityAccessCodeRedemption;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\Scraper;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('summarizes every location and can focus the analytics on one city', function () {
    $lawrence = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
        'state' => 'KS',
    ]);
    $jackson = City::factory()->create([
        'name' => 'Jackson',
        'slug' => 'jackson-ms',
        'state' => 'MS',
    ]);

    Scraper::create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence City News',
        'slug' => 'lawrence-city-news',
        'type' => 'rss',
        'source_url' => 'https://lawrence.example.gov/news.rss',
        'is_enabled' => true,
        'health_status' => 'healthy',
    ]);
    EventSource::factory()->create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence Events',
        'health_status' => 'healthy',
    ]);
    ChatSource::factory()->create([
        'city_id' => $jackson->id,
        'name' => 'Jackson Resident Guide',
    ]);

    Article::factory()->create([
        'city_id' => $lawrence->id,
        'title' => 'Lawrence street project',
        'created_at' => now()->subDay(),
    ]);
    Article::factory()->create([
        'city_id' => $jackson->id,
        'title' => 'Jackson city update',
        'created_at' => now()->subDay(),
    ]);
    Event::factory()->create([
        'city_id' => $lawrence->id,
        'title' => 'Lawrence council meeting',
        'starts_at' => now()->addDays(5),
    ]);

    $recentMember = User::factory()->create(['name' => 'Recent Lawrence Member']);
    $olderMember = User::factory()->create(['name' => 'Older Lawrence Member']);
    $jacksonMember = User::factory()->create(['name' => 'Jackson Member']);
    $lawrence->users()->attach($recentMember, [
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    $lawrence->users()->attach($olderMember, [
        'created_at' => now()->subDays(45),
        'updated_at' => now()->subDays(45),
    ]);
    $jackson->users()->attach($jacksonMember, [
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $libraryCode = CityAccessCode::query()->create([
        'city_id' => $lawrence->id,
        'label' => 'Library newsletter',
        'code_hash' => Hash::make('lawrence-library'),
        'lookup_digest' => CityAccessCode::lookupDigest('lawrence-library'),
        'is_active' => true,
        'last_redeemed_at' => now()->subDays(2),
    ]);
    CityAccessCodeRedemption::query()->create([
        'city_access_code_id' => $libraryCode->id,
        'city_id' => $lawrence->id,
        'user_id' => $recentMember->id,
        'redeemed_at' => now()->subDays(2),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSet('totalCities', 2)
        ->assertSet('totalSources', 3)
        ->assertSet('articlesLast7d', 2)
        ->assertSet('upcomingEvents', 1)
        ->assertSee('Lawrence')
        ->assertSee('Jackson')
        ->assertSee(route('admin.cities.preview', $lawrence), false)
        ->assertSee(route('admin.cities.preview', $jackson), false)
        ->set('cityId', $lawrence->id)
        ->assertSet('selectedCityName', 'Lawrence')
        ->assertSet('totalSources', 2)
        ->assertSet('articlesLast7d', 1)
        ->assertSet('upcomingEvents', 1)
        ->assertSet('memberCount', 2)
        ->assertSet('newMembersLast7d', 1)
        ->assertSet('newMembersLast30d', 1)
        ->assertSet('activeAccessCodes', 1)
        ->assertSet('attributedMemberCount', 1)
        ->assertSet('unattributedMemberCount', 1)
        ->assertSet('citySnapshots', fn ($snapshots): bool => $snapshots->count() === 1 && $snapshots->first()->is($lawrence))
        ->assertSee('Lawrence')
        ->assertSee('User analytics')
        ->assertSee('Library newsletter')
        ->assertSee('Recent Lawrence Member')
        ->assertDontSee('Jackson Member')
        ->assertDontSee(route('admin.cities.preview', $jackson), false);
});

it('provides an admin-only slug route for each city analytics page', function () {
    $city = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
    ]);

    expect(route('admin.cities.analytics', $city))
        ->toEndWith('/admin/cities/lawrence-ks/analytics');

    Livewire::test(Dashboard::class, ['city' => $city])
        ->assertSet('cityId', $city->id)
        ->assertSet('selectedCityName', 'Lawrence')
        ->assertSee('User analytics');

    $this->get(route('admin.cities.analytics', $city))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->withoutTwoFactor()->create())
        ->get(route('admin.cities.analytics', $city))
        ->assertForbidden();
});
