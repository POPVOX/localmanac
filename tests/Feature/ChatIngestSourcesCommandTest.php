<?php

use App\Jobs\EmbedChatSourcePage;
use App\Jobs\IngestChatSource;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Services\Chat\Ingestion\ChatSourceCrawler;
use Illuminate\Support\Facades\Queue;

it('creates tracked queued runs when dispatching chat ingestion from the command', function () {
    Queue::fake();

    $city = City::factory()->create([
        'slug' => 'chat-command-city',
    ]);

    $activeSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    $inactiveSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'is_active' => false,
    ]);

    $this->artisan('chat:ingest-sources', [
        '--city' => $city->slug,
        '--include-inactive' => true,
    ])->assertExitCode(0);

    expect(ChatSourceIngestionRun::query()->where('chat_source_id', $activeSource->id)->where('status', 'queued')->exists())->toBeTrue()
        ->and(ChatSourceIngestionRun::query()->where('chat_source_id', $inactiveSource->id)->where('status', 'queued')->exists())->toBeTrue();

    Queue::assertPushed(IngestChatSource::class, 2);
    Queue::assertPushed(IngestChatSource::class, fn (IngestChatSource $job): bool => $job->chatSourceId === $inactiveSource->id
        && $job->allowInactive === true
        && $job->runId !== null);
});

it('creates tracked runs in sync mode and updates run metrics', function () {
    Queue::fake();

    $city = City::factory()->create();
    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://example.com/faq',
        'is_active' => true,
    ]);

    $existingPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/faq',
        'canonical_url' => 'https://example.com/faq',
        'title' => 'FAQ',
        'content_text' => 'Old FAQ content',
        'content_length' => 15,
        'content_hash' => sha1('Old FAQ content'),
    ]);

    $crawler = Mockery::mock(ChatSourceCrawler::class);
    $crawler->shouldReceive('crawl')
        ->once()
        ->andReturn([
            [
                'url' => 'https://example.com/faq',
                'canonical_url' => 'https://example.com/faq',
                'title' => 'FAQ',
                'content_type' => 'html',
                'renderer' => 'http',
                'status_code' => 200,
                'fetch_duration_ms' => 22,
                'content_text' => 'Updated FAQ content',
                'content_length' => 19,
            ],
        ]);

    app()->instance(ChatSourceCrawler::class, $crawler);

    $this->artisan('chat:ingest-sources', [
        '--source' => [$source->id],
        '--sync' => true,
    ])->assertExitCode(0);

    $run = ChatSourceIngestionRun::query()->where('chat_source_id', $source->id)->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run?->status)->toBe('success')
        ->and($run?->pages_found)->toBe(1)
        ->and($run?->pages_changed)->toBe(1)
        ->and($run?->pages_embedded)->toBe(1)
        ->and($source->fresh()?->last_run_at)->not->toBeNull()
        ->and($existingPage->fresh()?->content_text)->toBe('Updated FAQ content');

    Queue::assertPushed(EmbedChatSourcePage::class, fn (EmbedChatSourcePage $job): bool => $job->chatSourcePageId === $existingPage->id);
    Queue::assertNotPushed(IngestChatSource::class);
});
