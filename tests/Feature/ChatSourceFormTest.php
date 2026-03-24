<?php

use App\Jobs\IngestChatSource;
use App\Livewire\Admin\ChatSources\Form as ChatSourceForm;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('stores frequency when saving a chat source', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();

    Livewire::actingAs($user)->test(ChatSourceForm::class)
        ->set('name', 'FAQ Source')
        ->set('cityId', $city->id)
        ->set('sourceUrl', 'https://example.com/faq')
        ->set('frequency', 'weekly')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.chat-sources.index'));

    $source = ChatSource::query()->first();

    expect($source)->not->toBeNull()
        ->and($source?->frequency)->toBe('weekly');
});

it('shows operations for existing sources and can queue a run', function () {
    Queue::fake();

    $user = User::factory()->create();
    $source = ChatSource::factory()->create([
        'is_active' => true,
    ]);

    Livewire::actingAs($user)->test(ChatSourceForm::class, ['source' => $source])
        ->assertSee('Run now')
        ->assertSee('View details')
        ->call('queueRun');

    $run = ChatSourceIngestionRun::query()->where('chat_source_id', $source->id)->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run?->status)->toBe('queued');

    Queue::assertPushed(IngestChatSource::class, fn (IngestChatSource $job): bool => $job->chatSourceId === $source->id
        && $job->runId === $run?->id
        && $job->force === false);
});

it('does not show run controls when creating a new chat source', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ChatSourceForm::class)
        ->assertDontSee('Run now')
        ->assertDontSee('View details');
});
