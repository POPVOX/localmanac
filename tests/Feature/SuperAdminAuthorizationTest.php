<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('denies raw scraper config access to regular users', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('manage-raw-scraper-config'))->toBeFalse();
});

it('allows raw scraper config access to super admins', function () {
    $user = User::factory()->superAdmin()->create();

    expect(Gate::forUser($user)->allows('manage-raw-scraper-config'))->toBeTrue();
});

it('denies admin dashboard access to regular users', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('access-admin'))->toBeFalse();
});

it('allows admin dashboard access to super admins', function () {
    $user = User::factory()->superAdmin()->create();

    expect(Gate::forUser($user)->allows('access-admin'))->toBeTrue();
});
