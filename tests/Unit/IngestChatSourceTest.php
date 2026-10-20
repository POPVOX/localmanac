<?php

use App\Jobs\EmbedChatSourcePage;
use App\Jobs\IngestChatSource;
use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourceIngestionRun;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Services\Chat\Ingestion\ChatSourceCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses the configured crawl job timeout', function () {
    config()->set('chat.crawl_job_timeout', 1800);

    $job = new IngestChatSource(123);

    expect($job->timeout)->toBe(1800)
        ->and($job->queue)->toBe('ingestion');
});

it('routes Chat crawling and embedding to the configured workers instead of the web queue default', function () {
    config()->set('queue.default', 'database');
    config()->set('chat.queue_connection', 'redis');

    $crawl = new IngestChatSource(123);
    $embedding = new EmbedChatSourcePage(456);

    expect($crawl->connection)->toBe('redis')
        ->and($crawl->queue)->toBe('ingestion')
        ->and($embedding->connection)->toBe('redis')
        ->and($embedding->queue)->toBe('embedding');

    config()->set('chat.queue_connection', 'database');
    expect((new IngestChatSource(123))->connection)->toBe('database')
        ->and((new EmbedChatSourcePage(456))->connection)->toBe('database');
});

it('does not replay a delayed job after its run has started or finished', function (string $status) {
    $source = ChatSource::factory()->create();
    $run = ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $source->id,
        'status' => $status,
    ]);
    $runner = Mockery::mock(\App\Services\Chat\Ingestion\ChatSourceIngestionRunner::class);
    $runner->shouldNotReceive('runExisting');

    (new IngestChatSource($source->id, false, $run->id))->handle($runner);

    expect($run->fresh()->status)->toBe($status);
})->with(['running', 'success', 'failed']);

it('purges blocked infrastructure pages and their chunks during reingest', function () {
    Queue::fake();

    $city = City::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'source_url' => 'https://www.wichita.gov/m/faq',
        'is_active' => true,
    ]);

    $blockedPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/cdn-cgi/l/email-protection',
        'canonical_url' => null,
        'title' => 'Email Protection | Cloudflare',
        'content_text' => 'The website from which you got to this page is protected by Cloudflare.',
        'content_length' => 69,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $blockedPage->id,
        'content' => 'Cloudflare email protection content',
        'content_length' => 34,
    ]);

    $existingPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/m/faq',
        'canonical_url' => 'https://www.wichita.gov/m/faq',
        'title' => 'Frequently Asked Questions',
        'content_text' => 'Old FAQ content',
        'content_length' => 15,
        'content_hash' => sha1('Old FAQ content'),
    ]);

    $crawler = Mockery::mock(ChatSourceCrawler::class);
    $crawler->shouldReceive('crawl')
        ->once()
        ->withArgs(fn (ChatSource $chatSource): bool => $chatSource->is($source))
        ->andReturn([
            [
                'url' => 'https://www.wichita.gov/m/faq',
                'canonical_url' => 'https://www.wichita.gov/m/faq',
                'title' => 'Frequently Asked Questions',
                'content_type' => 'html',
                'renderer' => 'http',
                'status_code' => 200,
                'fetch_duration_ms' => 12,
                'content_text' => 'Updated FAQ content',
                'content_length' => 19,
            ],
        ]);

    app()->instance(ChatSourceCrawler::class, $crawler);

    $run = ChatSourceIngestionRun::create([
        'chat_source_id' => $source->id,
        'status' => 'queued',
        'pages_found' => 0,
        'pages_changed' => 0,
        'pages_embedded' => 0,
    ]);

    $job = new IngestChatSource($source->id, false, $run->id);
    $job->handle(app(\App\Services\Chat\Ingestion\ChatSourceIngestionRunner::class));

    expect(ChatSourcePage::query()->whereKey($blockedPage->id)->exists())->toBeFalse()
        ->and(ChatSourceChunk::query()->where('chat_source_page_id', $blockedPage->id)->exists())->toBeFalse()
        ->and($existingPage->fresh()?->content_text)->toBe('Updated FAQ content')
        ->and($run->fresh()?->status)->toBe('success')
        ->and($run->fresh()?->pages_found)->toBe(1)
        ->and($run->fresh()?->pages_changed)->toBe(1)
        ->and($run->fresh()?->pages_embedded)->toBe(1);

    Queue::assertPushed(EmbedChatSourcePage::class, fn (EmbedChatSourcePage $job): bool => $job->chatSourcePageId === $existingPage->id);
});
