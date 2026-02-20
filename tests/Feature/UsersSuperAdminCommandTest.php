<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('grants and revokes super admin privileges by email', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'is_super_admin' => false,
    ]);

    $this->artisan('users:super-admin admin@example.com')
        ->assertExitCode(0);

    expect($user->fresh()?->is_super_admin)->toBeTrue();

    $this->artisan('users:super-admin admin@example.com --revoke')
        ->assertExitCode(0);

    expect($user->fresh()?->is_super_admin)->toBeFalse();
});

it('fails when user email does not exist', function () {
    $this->artisan('users:super-admin missing@example.com')
        ->assertExitCode(1);
});
