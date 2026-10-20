<?php

use App\Jobs\IngestChatSource;
use App\Livewire\Admin\ChatSources\Show;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('sends a manual retry to Horizon even when the web app defaults to the database queue', function () {
    config()->set('queue.default', 'database');
    config()->set('chat.queue_connection', 'redis');
    Queue::fake();
    $source = ChatSource::factory()->create(['is_active' => true]);

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test(Show::class, ['source' => $source])
        ->call('queueRun')
        ->assertHasNoErrors();

    $run = $source->runs()->sole();
    expect($run->status)->toBe('queued');
    Queue::assertPushed(IngestChatSource::class, fn (IngestChatSource $job): bool => $job->chatSourceId === $source->id && $job->runId === $run->id
        && $job->connection === 'redis' && $job->queue === 'ingestion'
    );
});

it('renders chat source run history and failure details', function () {
    $admin = User::factory()->withoutTwoFactor()->superAdmin()->create();
    $source = ChatSource::factory()->create([
        'name' => 'City Services FAQ',
    ]);

    ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $source->id,
        'status' => 'success',
        'pages_found' => 8,
        'pages_changed' => 2,
        'pages_embedded' => 2,
    ]);

    ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $source->id,
        'status' => 'failed',
        'pages_found' => 3,
        'pages_changed' => 1,
        'pages_embedded' => 1,
        'error_message' => 'Crawler timed out.',
        'finished_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.chat-sources.show', $source))
        ->assertOk()
        ->assertSee('City Services FAQ')
        ->assertSee('Latest runs')
        ->assertSee('Ingestion failed')
        ->assertSee('Crawler timed out.')
        ->assertSee('Pages changed / embedded');
});
