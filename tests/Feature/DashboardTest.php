<?php

use App\Models\City;
use App\Models\IssueArea;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('unverified users are redirected to verification notice', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('verification.notice'));
});

test('verified users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()
        ->assertSee('data-testid="assistant-typing-indicator"', false)
        ->assertDontSee('data-testid="new-conversation-button"', false);
});

test('dashboard scrolls to the start of the latest assistant answer', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('queueScrollToLatestAssistantStart()', false)
        ->assertSee('scrollToLatestAssistantStart()', false);
});

test('dashboard shows task-oriented chat prompt chips', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('What changed this week?')
        ->assertSee('Upcoming meetings')
        ->assertSee('New permits & projects')
        ->assertSee('Service alerts');
});

test('editing a chip prompt clears its fallback intent', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $prompt = 'What new permits, rezonings, or major development projects were recently filed or approved in Wichita? Include status and key locations.';

    Livewire::test(\App\Livewire\Dashboard::class)
        ->call('applyPrompt', $prompt, 'permits_projects')
        ->assertSet('fallbackIntent', 'permits_projects')
        ->set('question', 'How do I get a demolition permit?')
        ->assertSet('fallbackIntent', null);
});

test('dashboard renders assistant markdown links as clickable anchors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(\App\Livewire\Dashboard::class)
        ->set('messages', [
            [
                'role' => 'assistant',
                'content' => 'See [Wichita Economic Development](https://www.wichita.gov/208/Economic-Development).',
            ],
        ])
        ->assertSee('Wichita Economic Development')
        ->assertSeeHtml('href="https://www.wichita.gov/208/Economic-Development"')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('rel="noopener noreferrer"');
});

test('regular users do not see admin dashboard link in dropdown', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()
        ->assertDontSee('Admin Dashboard');
});

test('super admins see admin dashboard link in dropdown', function () {
    $user = User::factory()->superAdmin()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()
        ->assertSee('Admin Dashboard')
        ->assertSee(route('admin.dashboard'), false);
});

test('super admins can visit the scrapers admin page', function () {
    $user = User::factory()->superAdmin()->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.scrapers.index'));
    $response->assertOk();
});

test('browse by category renders interactive filter controls', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $city = City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $issueArea = IssueArea::create([
        'city_id' => $city->id,
        'name' => 'Budget & Taxes',
        'slug' => 'budget-taxes',
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Browse by Category')
        ->assertSee('All categories')
        ->assertSee($issueArea->name)
        ->assertSee('wire:click="clearIssueArea"', false)
        ->assertSee('wire:click="selectIssueArea('.$issueArea->id.')"', false);
});
