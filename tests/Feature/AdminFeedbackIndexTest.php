<?php

use App\Models\City;
use App\Models\SiteFeedback;
use App\Models\User;

test('regular users cannot access the admin feedback index', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('admin.feedback.index'))
        ->assertForbidden();
});

test('super admins can access the admin feedback index', function () {
    $user = User::factory()->withoutTwoFactor()->superAdmin()->create();

    $this->actingAs($user)
        ->get(route('admin.feedback.index'))
        ->assertOk()
        ->assertSee('Feedback');
});

test('admin feedback index renders submissions', function () {
    $admin = User::factory()->withoutTwoFactor()->superAdmin()->create();
    $feedbackUser = User::factory()->create([
        'name' => 'Feedback User',
        'email' => 'feedback-user@example.com',
    ]);
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    SiteFeedback::factory()
        ->for($feedbackUser, 'user')
        ->for($city, 'city')
        ->create([
            'type' => 'trouble',
            'message' => 'Calendar filtering did not update when I switched cities.',
            'page_url' => 'https://localmanac.test/dashboard?city_id=1',
            'route_name' => 'dashboard',
        ]);

    $this->actingAs($admin)
        ->get(route('admin.feedback.index'))
        ->assertOk()
        ->assertSee('Feedback User')
        ->assertSee('feedback-user@example.com')
        ->assertSee('Trouble')
        ->assertSee('Calendar filtering did not update')
        ->assertSee('Wichita')
        ->assertSee('dashboard');
});

test('admin feedback index can filter by type', function () {
    $admin = User::factory()->withoutTwoFactor()->superAdmin()->create();

    SiteFeedback::factory()->create([
        'type' => 'trouble',
        'message' => 'Trouble feedback entry.',
    ]);

    SiteFeedback::factory()->create([
        'type' => 'like',
        'message' => 'Like feedback entry.',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.feedback.index', ['type' => 'trouble']))
        ->assertOk()
        ->assertSee('Trouble feedback entry.')
        ->assertDontSee('Like feedback entry.');
});
