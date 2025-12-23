<?php

use App\Jobs\RunScraperRun;
use App\Livewire\Admin\Scrapers\Index;
use App\Models\City;
use App\Models\Scraper;
use App\Models\ScraperRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('queues a scraper run from the admin index', function () {
    Queue::fake();

    $user = User::factory()->create();

    $city = City::create(['name' => 'Queue City', 'slug' => 'queue-city']);
    $scraper = Scraper::create([
        'city_id' => $city->id,
        'name' => 'Queued Scraper',
        'slug' => 'queued-scraper',
        'type' => 'rss',
        'is_enabled' => true,
        'source_url' => 'https://example.com/feed',
        'config' => [],
    ]);

    Livewire::actingAs($user)->test(Index::class)
        ->call('queueRun', $scraper->id);

    $run = ScraperRun::first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('queued')
        ->and($run->items_found)->toBe(0)
        ->and($run->items_created)->toBe(0)
        ->and($run->items_updated)->toBe(0);

    Queue::assertPushed(RunScraperRun::class, fn (RunScraperRun $job): bool => $job->runId === $run?->id);

    Livewire::actingAs($user)->test(Index::class)
        ->call('queueRun', $scraper->id);

    expect(ScraperRun::count())->toBe(1);
});
