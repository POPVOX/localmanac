<?php

use App\Livewire\Admin\ChatSources\Show;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\City;
use App\Models\User;
use App\Services\Admin\CityOverviewQuery;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
use App\Services\Chat\Ingestion\PageFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('reports denied HTTP access and missing browser rendering in the failed run', function () {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('chat.playwright_script', base_path('missing-renderer-for-test.mjs'));
    Http::fake(['https://city.example/services' => Http::response('Access denied', 403)]);
    $source = ChatSource::factory()->create([
        'source_url' => 'https://city.example/services',
        'crawl_renderer' => 'auto',
    ]);

    $run = app(ChatSourceIngestionRunner::class)->run($source);

    expect($run->status)->toBe('failed')
        ->and($run->pages_found)->toBe(0)
        ->and($run->error_message)->toContain('HTTP 403', 'browser renderer script is missing');
    Livewire::actingAs(User::factory()->create())->test(Show::class, ['source' => $source])
        ->assertSee('HTTP 403')->assertSee('browser renderer script is missing');
    Queue::assertNothingPushed();
});

it('clears fetch diagnostics after a later successful request', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://city.example/denied' => Http::response('Denied', 403),
        'https://city.example/readable' => Http::response('<article>Public service information</article>', 200),
    ]);
    $fetcher = app(PageFetcher::class);
    expect($fetcher->fetch('https://city.example/denied', 'http'))->toBeNull()
        ->and($fetcher->lastFailure)->toContain('HTTP 403');
    expect($fetcher->fetch('https://city.example/readable', 'http'))->not->toBeNull()
        ->and($fetcher->lastFailure)->toBeNull();
});

it('includes active failed Chat sources in city health and clears them after recovery', function () {
    $city = City::factory()->create();
    $failed = ChatSource::factory()->create(['city_id' => $city->id]);
    $paused = ChatSource::factory()->create(['city_id' => $city->id, 'is_active' => false]);
    foreach ([$failed, $paused] as $source) {
        ChatSourceIngestionRun::factory()->create(['chat_source_id' => $source->id, 'status' => 'failed']);
    }
    expect(app(CityOverviewQuery::class)->build()->findOrFail($city->id)->unhealthy_chat_sources_count)->toBe(1);
    ChatSourceIngestionRun::factory()->create(['chat_source_id' => $failed->id, 'status' => 'success']);
    expect(app(CityOverviewQuery::class)->build()->findOrFail($city->id)->unhealthy_chat_sources_count)->toBe(0);
});

it('explains that a queued run has not ingested content and flags a long wait', function () {
    $source = ChatSource::factory()->create();
    $run = ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $source->id, 'status' => 'queued', 'started_at' => null, 'finished_at' => null,
    ]);
    $run->forceFill(['created_at' => now()->subMinutes(20)])->save();

    Livewire::actingAs(User::factory()->create())->test(Show::class, ['source' => $source])
        ->assertSee('has not started fetching or indexing')
        ->assertSee('Check the ingestion worker and queue backlog');
});
