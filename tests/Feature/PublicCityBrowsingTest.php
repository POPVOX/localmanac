<?php

use App\Livewire\Dashboard;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AnswerSynthesizer;
use App\Services\Chat\AskService;
use App\Services\Chat\ChatSourceSelector;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Livewire\Livewire;

it('opens news chat and calendars across cities without an account or code', function () {
    $stamford = City::factory()->create(['name' => 'Stamford', 'slug' => 'stamford']);
    $lawrence = City::factory()->create(['name' => 'Lawrence', 'slug' => 'lawrence']);

    foreach ([$stamford, $lawrence] as $city) {
        $this->get(route('cities.show', $city))
            ->assertOk()
            ->assertSee('Ask LocAlmanac About '.$city->name)
            ->assertSee('Type your question here')
            ->assertDontSee('data-testid="chat-locked"', false)
            ->assertSee(route('cities.show', $stamford), false)
            ->assertSee(route('cities.show', $lawrence), false);

        $this->get(route('cities.calendar', $city))
            ->assertOk()
            ->assertSee(route('cities.calendar', $stamford), false)
            ->assertSee(route('cities.calendar', $lawrence), false);
    }

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

it('offers browsing from the landing login and registration pages', function () {
    City::factory()->create();
    $this->get(route('home'))->assertOk()->assertSee('No account or ZIP code required.');

    foreach (['login', 'register'] as $route) {
        $this->get(route($route))->assertOk()
            ->assertSee('Browse cities without signing in')
            ->assertSee(route('home').'#cities', false);
    }
});

it('answers guest questions without using or saving member conversation ids', function () {
    $city = City::factory()->create();
    session()->put('chat.conversation_id.city.'.$city->id, 'member-conversation');

    $service = mock(AskService::class);
    $service->shouldReceive('answerStreamingForUser')->once()
        ->andReturnUsing(function ($question, $cityId, $user, $conversationId, $onDelta) use ($city) {
            expect($cityId)->toBe($city->id)
                ->and($user)->toBeNull()
                ->and($conversationId)->toBeNull();
            $onDelta('Pickup is on Monday.');

            return ['answer' => 'Pickup is on Monday.', 'citations' => [], 'conversation_id' => 'unexpected-id'];
        });
    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class, ['city' => $city])
        ->set('conversationId', 'another-members-conversation')
        ->set('question', 'When is trash pickup?')
        ->call('ask')
        ->assertHasNoErrors()
        ->assertSet('conversationId', null)
        ->assertSee('Pickup is on Monday.');

    expect(session('chat.conversation_id.city.'.$city->id))->toBe('member-conversation');
    $this->assertDatabaseCount('users', 0);
});

it('allows an unverified user to ask about a city without membership', function () {
    $city = City::factory()->create();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);
    $service = mock(AskService::class);
    $service->shouldReceive('answerStreamingForUser')->once()
        ->andReturn(['answer' => 'Here are local services.', 'citations' => []]);
    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class, ['city' => $city])
        ->set('question', 'Where can I find city services?')->call('ask')
        ->assertHasNoErrors()->assertSee('Here are local services.');

    expect($user->cities()->exists())->toBeFalse();
});

it('limits anonymous questions across cities and rejects oversized questions', function () {
    config()->set('chat.rate_limit_per_minute', 1);
    $city = City::factory()->create();
    $otherCity = City::factory()->create();
    $service = mock(AskService::class);
    $service->shouldReceive('answerStreamingForUser')->once()
        ->andReturn(['answer' => 'Here are local services.', 'citations' => []]);
    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class, ['city' => $city])
        ->set('question', str_repeat('a', 5001))->call('ask')
        ->assertHasErrors(['question' => 'max'])
        ->set('question', 'Where is city hall?')->call('ask')->assertHasNoErrors();

    Livewire::test(Dashboard::class, ['city' => $otherCity])
        ->set('question', 'Where is city hall?')->call('ask')
        ->assertHasErrors('question')->assertSee('Please wait');
});

it('can restore the member only chat gate without closing public feeds', function () {
    config()->set('chat.public_access_enabled', false);
    $city = City::factory()->create();
    $service = mock(AskService::class);
    $service->shouldNotReceive('answerStreamingForUser');
    $this->instance(AskService::class, $service);

    Livewire::test(Dashboard::class, ['city' => $city])
        ->assertSee('data-testid="chat-locked"', false)
        ->set('question', 'Where is city hall?')->call('ask')->assertHasErrors('question');
    $this->get(route('cities.show', $city))->assertOk();
});

it('keeps administration and account settings protected', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    $this->get(route('admin.dashboard'))->assertForbidden();
});

it('does not look up conversation history for anonymous orchestration', function () {
    $city = City::factory()->create();
    $selector = mock(ChatSourceSelector::class);
    $selector->shouldReceive('select')->once()->andReturn(collect());
    $store = mock(ConversationStore::class);
    $store->shouldNotReceive('getLatestConversationMessages');
    $synthesizer = mock(AnswerSynthesizer::class);
    $synthesizer->shouldReceive('synthesizeStreaming')->once()
        ->withArgs(fn ($question, $city, $sources, $user, $conversationId) => $user === null && $conversationId === null)
        ->andReturn(['answer' => 'City hall is downtown.', 'citations' => [], 'confidence' => 0.9, 'source_mode' => 'local']);

    $result = (new AskService($selector, $synthesizer, $store))->answerStreamingForUser(
        'And where is it?', $city->id, null, 'member-conversation', fn () => null,
    );

    expect($result['conversation_id'])->toBeNull();
});

it('streams an anonymous answer without creating a stored conversation', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);
    config()->set('chat.seed_fallback_enabled', false);
    $city = City::factory()->create();
    StreamingChatAnswerAgent::fake(['City hall is downtown.']);
    $conversationCount = DB::table('agent_conversations')->count();
    $messageCount = DB::table('agent_conversation_messages')->count();

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: 'Where is city hall?', city: $city, sources: collect(), user: null,
        conversationId: 'member-conversation', onDelta: fn () => null,
    );

    expect($result['conversation_id'])->toBeNull()
        ->and(DB::table('agent_conversations')->count())->toBe($conversationCount)
        ->and(DB::table('agent_conversation_messages')->count())->toBe($messageCount);
    StreamingChatAnswerAgent::assertPrompted(fn ($prompt) => ! $prompt->agent->hasConversationParticipant());
});
