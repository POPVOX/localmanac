<?php

use App\Jobs\EmbedChatSourcePage;
use App\Models\ChatSource;
use App\Models\ChatSourcePage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->snapshotFile = tempnam(sys_get_temp_dir(), 'chat-snapshot-');
    $this->source = ChatSource::factory()->create([
        'is_active' => true, 'source_url' => 'https://city.example.gov/services',
        'last_run_at' => now()->subHours(2),
    ]);
    $this->capture = [
        'source_id' => $this->source->id,
        'url' => $this->source->source_url,
        'title' => 'City services',
        'content_text' => str_repeat('Residents may contact the service bureau for help with city services and public requests. ', 4),
        'captured_at' => now()->subMinutes(10)->toIso8601String(),
    ];
});

afterEach(function () {
    unlink($this->snapshotFile);
});

it('preserves capture provenance and a failed automatic crawl while queuing embeddings', function () {
    $run = $this->source->runs()->create(['status' => 'failed', 'error_message' => 'HTTP 403']);
    $lastRunAt = $this->source->last_run_at;
    file_put_contents($this->snapshotFile, json_encode([$this->capture]));

    $this->artisan('chat-sources:import-browser-snapshots', ['path' => $this->snapshotFile, '--city' => $this->source->city_id])
        ->assertSuccessful();

    $page = $this->source->pages()->sole();
    expect($page->renderer)->toBe('browser_snapshot')
        ->and($page->status_code)->toBeNull()
        ->and($page->canonical_url)->toBe($this->capture['url'])
        ->and($page->fetched_at->toIso8601String())->toBe($this->capture['captured_at'])
        ->and($this->source->fresh()->last_run_at->equalTo($lastRunAt))->toBeTrue()
        ->and($run->fresh()->status)->toBe('failed');
    Queue::assertPushed(EmbedChatSourcePage::class, fn ($job) => $job->chatSourcePageId === $page->id);
});

it('validates every capture before writing any page', function (string $case) {
    $invalid = $this->capture;
    match ($case) {
        'wrong city' => $invalid['source_id'] = ChatSource::factory()->create()->id,
        'other origin' => $invalid['url'] = 'https://unrelated.example.org/services',
        'blocked' => $invalid['title'] = 'Access Denied',
        'future' => $invalid['captured_at'] = now()->addDay()->toIso8601String(),
        'stale' => $invalid['captured_at'] = now()->subDays(2)->toIso8601String(),
        'duplicate' => null,
    };
    file_put_contents($this->snapshotFile, json_encode([$this->capture, $invalid]));

    $this->artisan('chat-sources:import-browser-snapshots', ['path' => $this->snapshotFile, '--city' => $this->source->city_id])
        ->assertFailed();

    expect(ChatSourcePage::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['wrong city', 'other origin', 'blocked', 'future', 'stale', 'duplicate']);

it('does not replace a newer indexed page', function () {
    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $this->source->id, 'url' => $this->capture['url'],
        'content_text' => 'Newer content', 'fetched_at' => now(),
    ]);
    file_put_contents($this->snapshotFile, json_encode([$this->capture]));

    $this->artisan('chat-sources:import-browser-snapshots', ['path' => $this->snapshotFile, '--city' => $this->source->city_id])
        ->assertFailed();

    expect($page->fresh()->content_text)->toBe('Newer content');
    Queue::assertNothingPushed();
});

it('supports a dry run and requires explicit city scope', function () {
    file_put_contents($this->snapshotFile, json_encode([$this->capture]));
    expect(Artisan::call('chat-sources:import-browser-snapshots', ['path' => $this->snapshotFile]))->toBe(1);
    $this->artisan('chat-sources:import-browser-snapshots', ['path' => $this->snapshotFile, '--city' => $this->source->city_id, '--dry-run' => true])
        ->assertSuccessful();
    expect(ChatSourcePage::count())->toBe(0);
    Queue::assertNothingPushed();
});
