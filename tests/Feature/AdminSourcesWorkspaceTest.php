<?php

use App\Jobs\IngestChatSource;
use App\Jobs\RunEventSourceIngestion;
use App\Jobs\RunScraperRun;
use App\Livewire\Admin\ChatSources\Show as ChatSourceShow;
use App\Livewire\Admin\EventSources\Show as EventSourceShow;
use App\Livewire\Admin\Scrapers\Show as ScraperShow;
use App\Livewire\Admin\Sources\Index;
use App\Models\Article;
use App\Models\ChatSource;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use App\Models\Scraper;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('combines source types into a location-aware inventory with preview links', function () {
    $lawrence = City::factory()->create([
        'name' => 'Lawrence',
        'slug' => 'lawrence-ks',
    ]);
    $jackson = City::factory()->create([
        'name' => 'Jackson',
        'slug' => 'jackson-ms',
    ]);

    Scraper::create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence News Feed',
        'slug' => 'lawrence-news-feed',
        'type' => 'rss',
        'source_url' => 'https://lawrence.example.gov/feed',
        'is_enabled' => true,
        'health_status' => 'healthy',
    ]);
    EventSource::factory()->create([
        'city_id' => $jackson->id,
        'name' => 'Jackson Calendar Feed',
        'source_url' => 'https://jackson.example.gov/calendar',
        'health_status' => 'unhealthy',
    ]);
    ChatSource::factory()->create([
        'city_id' => $lawrence->id,
        'name' => 'Lawrence Answer Library',
        'source_url' => 'https://lawrence.example.gov/residents',
    ]);

    Livewire::test(Index::class)
        ->assertViewHas('categoryCounts', [
            'article' => 1,
            'event' => 1,
            'chat' => 1,
        ])
        ->assertViewHas('attentionCount', 1)
        ->assertSee('Lawrence News Feed')
        ->assertSee('Jackson Calendar Feed')
        ->assertSee('Lawrence Answer Library')
        ->assertSee(route('admin.cities.preview', $lawrence), false)
        ->assertSee(route('admin.cities.preview', $jackson), false)
        ->set('cityId', $lawrence->id)
        ->assertSee('Lawrence News Feed')
        ->assertSee('Lawrence Answer Library')
        ->assertDontSee('Jackson Calendar Feed')
        ->set('kind', 'chat')
        ->assertSee('Lawrence Answer Library')
        ->assertDontSee('Lawrence News Feed');
});

it('queues a retry for article event and answer sources', function () {
    Queue::fake();

    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'City News',
        'slug' => 'city-news',
        'type' => 'rss',
        'source_url' => 'https://example.gov/news.rss',
        'is_enabled' => true,
    ]);
    $eventSource = EventSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Events',
        'source_type' => 'ics',
        'is_active' => true,
    ]);
    $chatSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Answers',
        'is_active' => true,
    ]);

    $component = Livewire::test(Index::class);

    $component
        ->call('retrySource', 'article', $scraper->id)
        ->call('retrySource', 'event', $eventSource->id)
        ->call('retrySource', 'chat', $chatSource->id);

    $articleRun = $scraper->runs()->sole();
    $eventRun = $eventSource->runs()->sole();
    $chatRun = $chatSource->runs()->sole();

    expect($articleRun->status)->toBe('queued')
        ->and($eventRun->status)->toBe('queued')
        ->and($chatRun->status)->toBe('queued');

    Queue::assertPushed(RunScraperRun::class, fn (RunScraperRun $job): bool => $job->runId === $articleRun->id);
    Queue::assertPushed(RunEventSourceIngestion::class, fn (RunEventSourceIngestion $job): bool => $job->eventSourceId === $eventSource->id && $job->runId === $eventRun->id);
    Queue::assertPushed(IngestChatSource::class, fn (IngestChatSource $job): bool => $job->chatSourceId === $chatSource->id && $job->runId === $chatRun->id);
});

it('deletes source configuration safely while preserving published articles and events', function () {
    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Bad Article Source',
        'slug' => 'bad-article-source',
        'type' => 'rss',
        'source_url' => 'https://example.gov/bad-news.rss',
        'is_enabled' => true,
    ]);
    $article = Article::factory()->create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
    ]);
    $eventSource = EventSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Bad Event Source',
    ]);
    $event = Event::factory()->create(['city_id' => $city->id]);
    $eventItem = EventSourceItem::factory()->create([
        'event_id' => $event->id,
        'event_source_id' => $eventSource->id,
    ]);
    $chatSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Bad Answer Source',
    ]);
    $chatPage = ChatSourcePage::factory()->create(['chat_source_id' => $chatSource->id]);

    Livewire::test(Index::class)
        ->call('deleteSource', 'article', $scraper->id)
        ->call('deleteSource', 'event', $eventSource->id)
        ->call('deleteSource', 'chat', $chatSource->id);

    expect($scraper->fresh())->toBeNull()
        ->and($article->fresh())->not->toBeNull()
        ->and($article->fresh()?->scraper_id)->toBeNull()
        ->and($eventSource->fresh())->toBeNull()
        ->and($event->fresh())->not->toBeNull()
        ->and($eventItem->fresh())->toBeNull()
        ->and($chatSource->fresh())->toBeNull()
        ->and($chatPage->fresh())->toBeNull();
});

it('offers deletion from every source detail page', function () {
    $city = City::factory()->create();
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Article Source',
        'slug' => 'article-source',
        'type' => 'rss',
        'source_url' => 'https://example.gov/articles.rss',
        'is_enabled' => true,
    ]);
    $eventSource = EventSource::factory()->create(['city_id' => $city->id]);
    $chatSource = ChatSource::factory()->create(['city_id' => $city->id]);

    Livewire::test(ScraperShow::class, ['scraper' => $scraper])
        ->assertSee('Delete')
        ->call('deleteSource')
        ->assertRedirect(route('admin.sources.index'));
    Livewire::test(EventSourceShow::class, ['source' => $eventSource])
        ->assertSee('Delete')
        ->call('deleteSource')
        ->assertRedirect(route('admin.sources.index'));
    Livewire::test(ChatSourceShow::class, ['source' => $chatSource])
        ->assertSee('Delete')
        ->call('deleteSource')
        ->assertRedirect(route('admin.sources.index'));

    expect($scraper->fresh())->toBeNull()
        ->and($eventSource->fresh())->toBeNull()
        ->and($chatSource->fresh())->toBeNull();
});
