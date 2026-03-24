<?php

use App\Jobs\EmbedChatSourcePage;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\ChatSourcePage;
use App\Services\Chat\Ingestion\ChatSourceCrawler;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('records ingestion metrics and updates last run at on success', function () {
    Queue::fake();

    $source = ChatSource::factory()->create([
        'source_url' => 'https://example.com/faq',
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

    $run = ChatSourceIngestionRun::create([
        'chat_source_id' => $source->id,
        'status' => 'queued',
        'pages_found' => 0,
        'pages_changed' => 0,
        'pages_embedded' => 0,
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
                'fetch_duration_ms' => 30,
                'content_text' => 'Updated FAQ content',
                'content_length' => 19,
            ],
        ]);

    app()->instance(ChatSourceCrawler::class, $crawler);

    $result = app(ChatSourceIngestionRunner::class)->runExisting($run);

    expect($result->status)->toBe('success')
        ->and($result->pages_found)->toBe(1)
        ->and($result->pages_changed)->toBe(1)
        ->and($result->pages_embedded)->toBe(1)
        ->and($source->fresh()?->last_run_at)->not->toBeNull()
        ->and($existingPage->fresh()?->content_text)->toBe('Updated FAQ content');

    Queue::assertPushed(EmbedChatSourcePage::class, fn (EmbedChatSourcePage $job): bool => $job->chatSourcePageId === $existingPage->id);
});

it('stores failure details when crawling throws an exception', function () {
    Queue::fake();

    $source = ChatSource::factory()->create([
        'source_url' => 'https://example.com/faq',
    ]);

    $run = ChatSourceIngestionRun::create([
        'chat_source_id' => $source->id,
        'status' => 'queued',
        'pages_found' => 0,
        'pages_changed' => 0,
        'pages_embedded' => 0,
    ]);

    $crawler = Mockery::mock(ChatSourceCrawler::class);
    $crawler->shouldReceive('crawl')
        ->once()
        ->andThrow(new RuntimeException('Crawler blew up.'));

    app()->instance(ChatSourceCrawler::class, $crawler);

    $result = app(ChatSourceIngestionRunner::class)->runExisting($run);

    expect($result->status)->toBe('failed')
        ->and($result->error_class)->toBe(RuntimeException::class)
        ->and($result->error_message)->toBe('Crawler blew up.')
        ->and($source->fresh()?->last_run_at)->not->toBeNull();

    Queue::assertNothingPushed();
});
