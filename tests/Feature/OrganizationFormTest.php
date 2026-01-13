<?php

use App\Livewire\Admin\Organizations\Form as OrganizationForm;
use App\Models\City;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('updates an organization', function () {
    $user = User::factory()->create();
    $city = City::create([
        'name' => 'Org City',
        'slug' => 'org-city',
        'state' => 'CO',
        'country' => 'US',
        'timezone' => 'America/Denver',
    ]);

    $organization = Organization::create([
        'city_id' => $city->id,
        'name' => 'Old Org',
        'slug' => 'old-org',
        'type' => 'business',
    ]);

    Livewire::actingAs($user)->test(OrganizationForm::class, ['organization' => $organization])
        ->set('cityId', $city->id)
        ->set('name', 'New Org')
        ->set('slug', 'new-org')
        ->set('type', 'nonprofit')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.organizations.index'));

    $organization->refresh();

    expect($organization->slug)->toBe('new-org')
        ->and($organization->name)->toBe('New Org')
        ->and($organization->type)->toBe('nonprofit');
});
