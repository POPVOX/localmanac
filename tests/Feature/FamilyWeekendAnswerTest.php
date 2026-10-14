<?php

use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AskService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'America/Chicago')->utc());
    config(['chat.events.enabled' => true, 'chat.events.intent_mode' => 'intent']);
});
afterEach(fn () => Carbon::setTestNow());

it('answers the tester question with distinct family events in the city weekend and usable contact links', function () {
    $city = City::factory()->create(['name' => 'Houston', 'state' => 'Texas', 'timezone' => 'America/Chicago']);
    $base = ['city_id' => $city->id, 'all_day' => false, 'starts_at' => Carbon::parse('2026-10-17 10:00:00', 'America/Chicago'), 'ends_at' => Carbon::parse('2026-10-17 12:00:00', 'America/Chicago')];
    Event::factory()->create(array_merge($base, [
        'title' => 'Family art workshop', 'description' => 'For kids and families. Call (713) 555-0123.',
        'location_name' => 'Art Center', 'location_address' => '123 Main Street, Houston, TX', 'event_url' => 'https://example.org/family-art',
    ]));
    Event::factory()->create(array_merge($base, [
        'title' => 'Family art workshop', 'description' => 'For kids and families.',
        'location_name' => null, 'location_address' => null, 'event_url' => 'https://example.org/family-art',
    ]));
    Event::factory()->create(array_merge($base, [
        'title' => 'Drunk Dracula', 'description' => 'Adults only. Not suitable for children.',
        'starts_at' => Carbon::parse('2026-10-11 19:00:00', 'America/Chicago'), 'ends_at' => Carbon::parse('2026-10-18 19:00:00', 'America/Chicago'),
    ]));
    Event::factory()->create(array_merge($base, ['title' => 'Restorative Yoga', 'description' => 'For relaxation and movement.']));
    Event::factory()->create(array_merge($base, ['title' => 'Expired family festival', 'description' => 'For kids.', 'starts_at' => now()->subWeek(), 'ends_at' => now()->subDays(6)]));
    Event::factory()->create(array_merge($base, ['city_id' => City::factory()->create()->id, 'title' => 'Another city family festival', 'description' => 'For kids.']));
    StreamingChatAnswerAgent::fake();
    $stream = '';
    $result = app(AskService::class)->answerStreamingForUser("What's happening for families this weekend?", $city->id, User::factory()->create(), null, function ($delta) use (&$stream) {
        $stream .= $delta;
    });

    expect($result['answer'])->toContain('Oct 16–Oct 18, 2026', 'Sat, Oct 17 10:00 AM', 'https://example.org/family-art', 'https://www.google.com/maps/search/', '123%20Main%20Street', 'tel:+17135550123')
        ->not->toContain('Drunk Dracula', 'Restorative Yoga', 'Expired family festival', 'Another city family festival')
        ->and(substr_count($result['answer'], 'Family art workshop'))->toBe(1)
        ->and($result['citations'])->toHaveCount(1)
        ->and($stream)->toBe($result['answer']);
    StreamingChatAnswerAgent::assertNeverPrompted();
});

it('shows a multi-day listing as a range instead of implying its old start is this weekend', function () {
    $city = City::factory()->create(['name' => 'Houston', 'timezone' => 'America/Chicago']);
    Event::factory()->create([
        'city_id' => $city->id, 'title' => 'Family autumn exhibit', 'description' => 'All ages welcome.', 'all_day' => true,
        'starts_at' => Carbon::parse('2026-10-01', 'America/Chicago'), 'ends_at' => Carbon::parse('2026-11-01', 'America/Chicago'),
        'event_url' => 'https://example.org/exhibit',
    ]);
    StreamingChatAnswerAgent::fake();
    $result = app(AskService::class)->answerStreamingForUser('Family events this weekend', $city->id, User::factory()->create(), null, fn () => null);
    expect($result['answer'])->toContain('Oct 1–Oct 31, 2026 (multi-day listing; check individual dates and hours)')->not->toContain('Thu, Oct 1 at');
    StreamingChatAnswerAgent::assertNeverPrompted();
});

it('does not replace an empty family result with adult or unrelated events', function () {
    $city = City::factory()->create(['name' => 'Houston', 'timezone' => 'America/Chicago']);
    Event::factory()->create(['city_id' => $city->id, 'title' => 'Adult night', 'description' => '21+ event with a family-owned restaurant.', 'starts_at' => now()->addDays(3)]);
    StreamingChatAnswerAgent::fake();
    $result = app(AskService::class)->answerStreamingForUser('What is happening for families this weekend?', $city->id, User::factory()->create(), null, fn () => null);
    expect($result['answer'])->toContain('could not verify any family-friendly events')->not->toContain('Adult night')
        ->and($result['citations'])->toBe([]);
    StreamingChatAnswerAgent::assertNeverPrompted();
});
