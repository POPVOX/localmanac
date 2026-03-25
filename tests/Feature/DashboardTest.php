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
        ->assertDontSee('data-testid="new-conversation-button"', false)
        ->assertSee('Live with real Wichita data! Try our AI assistant to get your Wichita questions answered.')
        ->assertDontSee('Live with real Wichita data! Try the AI assistant above.');
});

test('dashboard scrolls to the start of the latest assistant answer', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('queueScrollToLatestAssistantStart()', false)
        ->assertSee('scrollToLatestAssistantStart()', false);
});

test('dashboard queues a bottom scroll when a follow-up question is submitted', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('handleSubmit()', false)
        ->assertSee('queueScrollToBottom()', false);
});

test('dashboard shows task-oriented chat prompt chips', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee("What's new this week?")
        ->assertSee('Upcoming meetings')
        ->assertSee('How do I...?')
        ->assertSee('Service alerts');
});

test('applying a chip prompt fills the composer question', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $prompt = 'How do I apply for a building permit in Wichita? What documents do I need and where do I submit the application?';

    Livewire::test(\App\Livewire\Dashboard::class)
        ->call('applyPrompt', $prompt)
        ->assertSet('question', $prompt);
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

test('dashboard spaces paragraphs after markdown lists in assistant answers', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(\App\Livewire\Dashboard::class)
        ->set('messages', [
            [
                'role' => 'assistant',
                'content' => "1. First item\n2. Second item\n\nFollow-up paragraph.",
            ],
        ])
        ->assertSeeHtml('[&_ol+p]:mt-3')
        ->assertSeeHtml('[&_ul+p]:mt-3');
});

test('dashboard no longer renders legacy answer resources', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(\App\Livewire\Dashboard::class)
        ->set('messages', [
            [
                'role' => 'assistant',
                'content' => 'Use the citation below.',
                'resources' => [
                    [
                        'type' => 'link',
                        'label' => 'Legacy Resource Label',
                        'value' => 'Legacy Resource Value',
                        'url' => 'https://example.com/legacy-resource',
                    ],
                ],
                'citations' => [
                    [
                        'title' => 'Current Citation',
                        'source_url' => 'https://example.com/current-citation',
                        'type' => 'html',
                    ],
                ],
            ],
        ])
        ->assertSee('Current Citation')
        ->assertDontSee('Legacy Resource Label')
        ->assertDontSee('Legacy Resource Value');
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
