<?php

use App\Livewire\Feedback\Widget;
use App\Models\SiteFeedback;
use App\Models\User;
use Livewire\Livewire;

test('logged-in users can submit feedback from the widget', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Widget::class)
        ->set('type', 'suggestion')
        ->set('message', 'Please add better filters for finding project updates by neighborhood.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    $feedback = SiteFeedback::query()->first();

    expect($feedback)->not->toBeNull()
        ->and($feedback?->user_id)->toBe($user->id)
        ->and($feedback?->type?->value)->toBe('suggestion')
        ->and($feedback?->message)->toContain('better filters');
});

test('widget validates required fields and message length', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Widget::class)
        ->set('type', '')
        ->set('message', '')
        ->call('submit')
        ->assertHasErrors(['type', 'message']);

    Livewire::actingAs($user)->test(Widget::class)
        ->set('type', 'like')
        ->set('message', 'Too short')
        ->call('submit')
        ->assertHasErrors(['message']);
});

test('feedback trigger is visible to authenticated users on home page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('data-test="feedback-trigger"', false);
});

test('feedback trigger is hidden from guests on home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-test="feedback-trigger"', false);
});
