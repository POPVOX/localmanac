<?php

use App\Livewire\Admin\Cities\AccessCodes as CityAccessCodes;
use App\Livewire\Admin\Cities\Form as CityForm;
use App\Livewire\Dashboard;
use App\Models\Article;
use App\Models\City;
use App\Models\CityAccessCode;
use App\Models\CityAccessCodeRedemption;
use App\Models\User;
use App\Services\Access\CityAccessGrantService;
use App\Services\Chat\AskService;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('landing page links to every public city feed', function () {
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

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Choose your city')
        ->assertSee(route('cities.show', $lawrence), false)
        ->assertSee(route('cities.show', $jackson), false);
});

test('city news feeds are public and scoped by slug while chat remains locked', function () {
    $lawrence = City::factory()->create(['name' => 'Lawrence', 'slug' => 'lawrence-ks']);
    $jackson = City::factory()->create(['name' => 'Jackson', 'slug' => 'jackson-ms']);

    Article::factory()->create([
        'city_id' => $lawrence->id,
        'title' => 'Lawrence City Commission Update',
    ]);
    Article::factory()->create([
        'city_id' => $jackson->id,
        'title' => 'Jackson Council Update',
    ]);

    $this->get(route('cities.show', $lawrence))
        ->assertOk()
        ->assertSee('Lawrence City Commission Update')
        ->assertDontSee('Jackson Council Update')
        ->assertSee('data-testid="chat-locked"', false)
        ->assertSee(route('cities.calendar', $lawrence), false);
});

test('a valid city code grants only that city to a signed-in user', function () {
    $lawrence = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
        'chat_access_code_hash' => Hash::make('lawrence-access'),
    ]);
    $jackson = City::factory()->create([
        'name' => 'Jackson',
        'slug' => 'jackson-ms',
        'chat_access_code_hash' => Hash::make('jackson-access'),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Dashboard::class, ['city' => $lawrence])
        ->assertSee('data-testid="chat-locked"', false)
        ->set('cityAccessCode', 'lawrence-access')
        ->call('unlockCityChat')
        ->assertHasNoErrors()
        ->assertSet('chatAccessGranted', true)
        ->assertDontSee('data-testid="chat-locked"', false);

    expect($user->fresh()->canAccessCity($lawrence))->toBeTrue()
        ->and($user->fresh()->canAccessCity($jackson))->toBeFalse();
});

test('multiple named codes grant the same city and retain campaign attribution', function () {
    $city = City::factory()->create(['name' => 'Lawrence', 'slug' => 'lawrence-ks']);
    $libraryCode = CityAccessCode::query()->create([
        'city_id' => $city->id,
        'label' => 'Library newsletter',
        'code_hash' => Hash::make('library-fall-2026'),
        'lookup_digest' => CityAccessCode::lookupDigest('library-fall-2026'),
        'is_active' => true,
    ]);
    $neighborhoodCode = CityAccessCode::query()->create([
        'city_id' => $city->id,
        'label' => 'Neighborhood leaders',
        'code_hash' => Hash::make('neighborhood-2026'),
        'lookup_digest' => CityAccessCode::lookupDigest('neighborhood-2026'),
        'is_active' => true,
    ]);
    $libraryMember = User::factory()->create();
    $neighborhoodMember = User::factory()->create();
    $grantService = app(CityAccessGrantService::class);

    $grantService->grant(
        $libraryMember,
        $city,
        CityAccessCode::findMatchingAvailable('library-fall-2026', $city->id),
    );
    $grantService->grant(
        $neighborhoodMember,
        $city,
        CityAccessCode::findMatchingAvailable('neighborhood-2026', $city->id),
    );

    expect(CityAccessCodeRedemption::query()->where('city_access_code_id', $libraryCode->id)->count())->toBe(1)
        ->and(CityAccessCodeRedemption::query()->where('city_access_code_id', $neighborhoodCode->id)->count())->toBe(1)
        ->and($libraryMember->fresh()->canAccessCity($city))->toBeTrue()
        ->and($neighborhoodMember->fresh()->canAccessCity($city))->toBeTrue();
});

test('chat rejects a user who has not been granted the selected city', function () {
    $city = City::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $service = mock(AskService::class);
    $service->shouldNotReceive('answerStreamingForUser');
    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class, ['city' => $city])
        ->set('question', 'What changed today?')
        ->call('ask')
        ->assertHasErrors('question')
        ->assertSet('messages', []);
});

test('super administrators can use chat in every city without a code', function () {
    $lawrence = City::factory()->create(['name' => 'Lawrence']);
    $jackson = City::factory()->create(['name' => 'Jackson']);
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin);

    Livewire::test(Dashboard::class, ['city' => $lawrence])
        ->assertDontSee('data-testid="chat-locked"', false)
        ->assertSee('Type your question here');

    Livewire::test(Dashboard::class, ['city' => $jackson])
        ->assertDontSee('data-testid="chat-locked"', false)
        ->assertSee('Type your question here');
});

test('registration can grant access using a city code', function () {
    $city = City::factory()->create([
        'chat_access_code_hash' => Hash::make('member-code-123'),
    ]);

    $this->post(route('register.store'), [
        'name' => 'New Member',
        'email' => 'member@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'city_code' => 'member-code-123',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'member@example.com')->firstOrFail();

    expect($user->canAccessCity($city))->toBeTrue();
});

test('registration records which named city code granted access', function () {
    $city = City::factory()->create();
    $accessCode = CityAccessCode::query()->create([
        'city_id' => $city->id,
        'label' => 'Partner mailing',
        'code_hash' => Hash::make('partner-mailing-2026'),
        'lookup_digest' => CityAccessCode::lookupDigest('partner-mailing-2026'),
        'is_active' => true,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Campaign Member',
        'email' => 'campaign@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'city_code' => 'partner-mailing-2026',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'campaign@example.com')->firstOrFail();

    $this->assertDatabaseHas('city_access_code_redemptions', [
        'city_access_code_id' => $accessCode->id,
        'city_id' => $city->id,
        'user_id' => $user->id,
    ]);
});

test('registration rejects an invalid city code', function () {
    $this->post(route('register.store'), [
        'name' => 'New Member',
        'email' => 'member@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'city_code' => 'not-a-real-code',
    ])->assertSessionHasErrors('city_code');

    $this->assertGuest();
});

test('administrators can configure jurisdiction details', function () {
    $this->actingAs(User::factory()->superAdmin()->create());

    Livewire::test(CityForm::class, ['city' => null])
        ->set('name', 'Lawrence')
        ->set('slug', 'lawrence-ks')
        ->set('state', 'KS')
        ->set('country', 'US')
        ->set('timezone', 'America/Chicago')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.cities.index'));

    $city = City::query()->where('slug', 'lawrence-ks')->firstOrFail();

    expect($city->state)->toBe('KS')
        ->and($city->country)->toBe('US')
        ->and($city->timezone)->toBe('America/Chicago');
});

test('administrators can create and pause named city access codes', function () {
    $city = City::factory()->create(['name' => 'Lawrence', 'slug' => 'lawrence-ks']);
    $this->actingAs(User::factory()->superAdmin()->create());

    Livewire::test(CityAccessCodes::class, ['city' => $city])
        ->set('label', 'Downtown association')
        ->set('plainTextCode', 'downtown-lawrence-2026')
        ->set('description', 'Shared in the September member email')
        ->call('createCode')
        ->assertHasNoErrors()
        ->assertSet('createdCodePlainText', 'downtown-lawrence-2026')
        ->assertSee('Downtown association');

    $accessCode = $city->accessCodes()->firstOrFail();

    expect($accessCode->code_hash)->not->toBe('downtown-lawrence-2026')
        ->and($accessCode->lookup_digest)->not->toBe('downtown-lawrence-2026')
        ->and($accessCode->matches('downtown-lawrence-2026'))->toBeTrue();

    Livewire::test(CityAccessCodes::class, ['city' => $city])
        ->call('toggleCode', $accessCode->id)
        ->assertHasNoErrors();

    expect($accessCode->fresh()->is_active)->toBeFalse();
});

test('city access codes must be globally unique for reliable attribution', function () {
    $lawrence = City::factory()->create(['name' => 'Lawrence']);
    $jackson = City::factory()->create(['name' => 'Jackson']);
    CityAccessCode::query()->create([
        'city_id' => $lawrence->id,
        'label' => 'Partner code',
        'code_hash' => Hash::make('shared-partner-code'),
        'lookup_digest' => CityAccessCode::lookupDigest('shared-partner-code'),
        'is_active' => true,
    ]);
    $this->actingAs(User::factory()->superAdmin()->create());

    Livewire::test(CityAccessCodes::class, ['city' => $jackson])
        ->set('label', 'Another campaign')
        ->set('plainTextCode', 'shared-partner-code')
        ->call('createCode')
        ->assertHasErrors('plainTextCode');
});

test('paused and expired city access codes cannot grant access', function () {
    $pausedCity = City::factory()->create();
    $expiredCity = City::factory()->create();

    CityAccessCode::query()->create([
        'city_id' => $pausedCity->id,
        'label' => 'Paused campaign',
        'code_hash' => Hash::make('paused-campaign-code'),
        'lookup_digest' => CityAccessCode::lookupDigest('paused-campaign-code'),
        'is_active' => false,
    ]);
    CityAccessCode::query()->create([
        'city_id' => $expiredCity->id,
        'label' => 'Expired campaign',
        'code_hash' => Hash::make('expired-campaign-code'),
        'lookup_digest' => CityAccessCode::lookupDigest('expired-campaign-code'),
        'is_active' => true,
        'expires_at' => now()->subMinute(),
    ]);

    expect(CityAccessCode::findMatchingAvailable('paused-campaign-code'))->toBeNull()
        ->and(CityAccessCode::findMatchingAvailable('expired-campaign-code'))->toBeNull()
        ->and($pausedCity->hasChatAccessCode())->toBeFalse()
        ->and($expiredCity->hasChatAccessCode())->toBeFalse();
});
