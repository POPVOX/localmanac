<?php

use App\Livewire\Admin\Cities\Form as CityForm;
use App\Livewire\Dashboard;
use App\Models\Article;
use App\Models\City;
use App\Models\User;
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

test('administrators can configure jurisdiction details and a secure chat code', function () {
    $this->actingAs(User::factory()->superAdmin()->create());

    Livewire::test(CityForm::class, ['city' => null])
        ->set('name', 'Lawrence')
        ->set('slug', 'lawrence-ks')
        ->set('state', 'KS')
        ->set('country', 'US')
        ->set('timezone', 'America/Chicago')
        ->set('chatAccessCode', 'lawrence-access')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.cities.index'));

    $city = City::query()->where('slug', 'lawrence-ks')->firstOrFail();

    expect($city->state)->toBe('KS')
        ->and($city->country)->toBe('US')
        ->and($city->timezone)->toBe('America/Chicago')
        ->and($city->chat_access_code_hash)->not->toBe('lawrence-access')
        ->and($city->matchesChatAccessCode('lawrence-access'))->toBeTrue();
});
