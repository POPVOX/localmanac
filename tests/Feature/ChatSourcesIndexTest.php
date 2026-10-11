<?php

use App\Livewire\Admin\ChatSources\Index;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\User;
use Livewire\Livewire;

it('updates ingestion metrics and slow sources when switching cities or clearing the city filter', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $jackson = ChatSource::factory()->create();
    $houston = ChatSource::factory()->create();
    $emptyCity = City::factory()->create();
    ChatSourcePage::factory()->create([
        'chat_source_id' => $jackson->id, 'renderer' => 'http',
        'fetch_duration_ms' => 100, 'fetched_at' => now()->subHours(2),
    ]);
    ChatSourcePage::factory()->create([
        'chat_source_id' => $jackson->id, 'renderer' => 'playwright',
        'fetch_duration_ms' => 300, 'fetched_at' => now()->subDays(2),
    ]);
    ChatSourcePage::factory()->create([
        'chat_source_id' => $houston->id, 'renderer' => 'http',
        'fetch_duration_ms' => 900, 'fetched_at' => now()->subHour(),
    ]);

    $component = Livewire::actingAs(User::factory()->superAdmin()->create())->test(Index::class)
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 3 && $summary['pages_last_24h'] === 2)
        ->set('cityId', $jackson->city_id)
        ->assertViewHas('summary', fn ($summary) => $summary === [
            'total_pages' => 2, 'pages_last_24h' => 1, 'playwright_pages' => 1,
            'playwright_rate' => 50.0, 'avg_fetch_ms' => 200,
        ])
        ->assertViewHas('slowSources', fn ($sources) => $sources->pluck('id')->all() === [$jackson->id])
        ->set('cityId', $houston->city_id)
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 1 && $summary['avg_fetch_ms'] === 900 && $summary['playwright_pages'] === 0)
        ->assertViewHas('slowSources', fn ($sources) => $sources->pluck('id')->all() === [$houston->id])
        ->set('cityId', $emptyCity->id)
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 0 && $summary['avg_fetch_ms'] === null && $summary['playwright_rate'] === 0)
        ->assertViewHas('slowSources', fn ($sources) => $sources->isEmpty())
        ->set('cityId', null)
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 3 && $summary['avg_fetch_ms'] === 433)
        ->assertViewHas('slowSources', fn ($sources) => $sources->pluck('id')->all() === [$houston->id, $jackson->id]);
});

it('uses search and active filters consistently for the table and ingestion metrics', function () {
    $city = City::factory()->create(['name' => 'Jackson']);
    $permit = ChatSource::factory()->create(['city_id' => $city->id, 'name' => 'Permit Help', 'is_active' => true]);
    $library = ChatSource::factory()->create(['city_id' => $city->id, 'name' => 'Library', 'is_active' => true]);
    $disabled = ChatSource::factory()->create(['city_id' => $city->id, 'name' => 'Old Permit Help', 'is_active' => false]);
    $other = ChatSource::factory()->create(['name' => 'Permit Help', 'is_active' => true]);
    foreach ([$permit, $library, $disabled, $other] as $source) {
        ChatSourcePage::factory()->create(['chat_source_id' => $source->id, 'fetch_duration_ms' => 200]);
    }

    Livewire::actingAs(User::factory()->superAdmin()->create())->test(Index::class)
        ->set('cityId', $city->id)->set('activeOnly', true)->set('search', 'Permit Help')
        ->assertViewHas('sources', fn ($sources) => $sources->pluck('id')->all() === [$permit->id])
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 1)
        ->assertViewHas('slowSources', fn ($sources) => $sources->pluck('id')->all() === [$permit->id])
        ->set('search', 'Jackson')
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 2)
        ->set('activeOnly', false)
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 3)
        ->set('search', 'No matching sources')
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 0)
        ->assertViewHas('slowSources', fn ($sources) => $sources->isEmpty());
});

it('summarizes all matching sources regardless of the current table page', function () {
    $city = City::factory()->create();
    $sources = ChatSource::factory()->count(16)->create(['city_id' => $city->id]);
    foreach ($sources as $source) {
        ChatSourcePage::factory()->create(['chat_source_id' => $source->id, 'fetch_duration_ms' => 100]);
    }
    Livewire::actingAs(User::factory()->superAdmin()->create())->test(Index::class)
        ->set('cityId', $city->id)
        ->assertViewHas('sources', fn ($sources) => $sources->count() === 15)
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 16)
        ->call('setPage', 2)
        ->assertViewHas('sources', fn ($sources) => $sources->count() === 1)
        ->assertViewHas('summary', fn ($summary) => $summary['total_pages'] === 16);
});

it('shows slowest source metrics in a collapsed section', function () {
    $admin = User::factory()->withoutTwoFactor()->superAdmin()->create();

    $this->actingAs($admin)
        ->get(route('admin.chat-sources.index'))
        ->assertOk()
        ->assertSee('Show slowest sources')
        ->assertSee('Slowest Sources (avg fetch)')
        ->assertSeeHtml('<details class="group');
});

it('shows last run state and actions for chat sources', function () {
    $admin = User::factory()->withoutTwoFactor()->superAdmin()->create();
    $source = ChatSource::factory()->create([
        'name' => 'Permit Help',
    ]);

    ChatSourceIngestionRun::factory()->create([
        'chat_source_id' => $source->id,
        'status' => 'failed',
        'error_message' => 'Crawler failed.',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.chat-sources.index'))
        ->assertOk()
        ->assertSee('Permit Help')
        ->assertSee('Last run')
        ->assertSee('View')
        ->assertSee('Run')
        ->assertSee('Failed')
        ->assertSee('whitespace-nowrap');
});
