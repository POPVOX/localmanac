<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Operations') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">
                {{ __('Admin Dashboard') }}
            </flux:heading>
            <flux:subheading class="mt-2">{{ __('Coverage, publishing activity, and ingestion health at a glance.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.scrapers.create')" icon="plus" wire:navigate>
            {{ __('Add source') }}
        </flux:button>
    </div>

    <section aria-labelledby="coverage-summary">
        <h2 id="coverage-summary" class="sr-only">{{ __('Coverage summary') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['title' => __('Cities'), 'value' => $totalCities, 'icon' => 'map-pin'],
                ['title' => __('Organizations'), 'value' => $totalOrganizations, 'icon' => 'building-office-2'],
                ['title' => __('Active scrapers'), 'value' => "{$activeScrapers} / {$totalScrapers}", 'icon' => 'cpu-chip'],
                ['title' => __('Articles · 24h'), 'value' => $hasArticlesTable ? $articlesLast24h : '—', 'icon' => 'newspaper'],
            ] as $stat)
                <div class="admin-stat">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="admin-kicker">{{ $stat['title'] }}</div>
                            <div class="mt-4 text-3xl font-semibold tracking-[-0.04em] text-[#18342c]">{{ $stat['value'] }}</div>
                        </div>
                        <div class="grid size-10 place-items-center rounded-xl bg-[#e7f0eb] text-[#1f654f]">
                            <flux:icon :icon="$stat['icon']" class="size-5" />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-3" aria-label="{{ __('Ingestion pulse') }}">
        <div class="admin-panel p-5 sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="admin-kicker">{{ __('Article ingestion') }}</div>
                    <flux:heading size="lg" class="mt-2">{{ __('Publishing volume') }}</flux:heading>
                </div>
                <flux:icon icon="newspaper" class="size-5 text-[#1f654f]" />
            </div>
            @if ($hasArticlesTable)
                <dl class="mt-6 grid grid-cols-2 divide-x divide-[#d9d7ce] border-y border-[#d9d7ce] py-4">
                    <div class="pr-4">
                        <dt class="text-xs font-medium text-[#667970]">{{ __('Last 24 hours') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-[#18342c]">{{ $articlesLast24h }}</dd>
                    </div>
                    <div class="pl-4">
                        <dt class="text-xs font-medium text-[#667970]">{{ __('Last 7 days') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-[#18342c]">{{ $articlesLast7d }}</dd>
                    </div>
                </dl>
            @else
                <flux:text variant="subtle" class="mt-6">{{ __('Article statistics are not available yet.') }}</flux:text>
            @endif
        </div>

        <div class="admin-panel p-5 sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="admin-kicker">{{ __('Event ingestion') }}</div>
                    <flux:heading size="lg" class="mt-2">{{ __('Calendar volume') }}</flux:heading>
                </div>
                <flux:icon icon="calendar-days" class="size-5 text-[#1f654f]" />
            </div>
            @if ($hasEventRunsTable)
                <dl class="mt-6 grid grid-cols-2 divide-x divide-[#d9d7ce] border-y border-[#d9d7ce] py-4">
                    <div class="pr-4">
                        <dt class="text-xs font-medium text-[#667970]">{{ __('Last 24 hours') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-[#18342c]">{{ $eventsLast24h }}</dd>
                    </div>
                    <div class="pl-4">
                        <dt class="text-xs font-medium text-[#667970]">{{ __('Last 7 days') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-[#18342c]">{{ $eventsLast7d }}</dd>
                    </div>
                </dl>
            @else
                <flux:text variant="subtle" class="mt-6">{{ __('Event statistics are not available yet.') }}</flux:text>
            @endif
        </div>

        <div class="admin-panel flex flex-col justify-between p-5 sm:p-6">
            <div>
                <div class="admin-kicker">{{ __('Source coverage') }}</div>
                <flux:heading size="lg" class="mt-2">{{ __('Scraper summary') }}</flux:heading>
                <p class="mt-4 text-sm leading-6 text-[#5d7168]">
                    {{ trans_choice(':count active source is publishing.|:count active sources are publishing.', $activeScrapers, ['count' => $activeScrapers]) }}
                </p>
            </div>
            <flux:link :href="route('admin.scrapers.index')" class="mt-6 inline-flex font-semibold" wire:navigate>
                {{ __('Review all scrapers') }} →
            </flux:link>
        </div>
    </section>

    <section class="admin-panel overflow-hidden" aria-labelledby="recent-scrapers">
        <div class="flex items-center justify-between gap-4 border-b border-[#e1dfd7] px-5 py-5 sm:px-6">
            <div>
                <div class="admin-kicker">{{ __('Latest runs') }}</div>
                <flux:heading id="recent-scrapers" size="lg" class="mt-1">{{ __('Recent scraper activity') }}</flux:heading>
            </div>
            <flux:link :href="route('admin.scrapers.index')" class="font-semibold" wire:navigate>{{ __('View all') }}</flux:link>
        </div>

        <div class="overflow-x-auto px-3 pb-3 sm:px-5">
            <flux:table>
                <flux:table.columns sticky>
                    <flux:table.column sticky>{{ __('Scraper') }}</flux:table.column>
                    <flux:table.column>{{ __('City') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Finished at') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($recentRuns as $run)
                        <flux:table.row :key="$run->id">
                            <flux:table.cell variant="strong" sticky>
                                <flux:link :href="route('admin.scrapers.show', $run->scraper)" wire:navigate>{{ $run->scraper->name }}</flux:link>
                            </flux:table.cell>
                            <flux:table.cell>{{ $run->scraper->city?->name }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$run->status === 'success' ? 'green' : ($run->status === 'failed' ? 'red' : 'yellow')" variant="subtle">
                                    {{ ucfirst($run->status) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ ($run->finished_at ?? $run->started_at)?->toDayDateTimeString() ?? __('Pending') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4"><flux:text variant="subtle">{{ __('No scraper activity recorded yet.') }}</flux:text></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </section>

    <section class="admin-panel overflow-hidden" aria-labelledby="recent-events">
        <div class="flex items-center justify-between gap-4 border-b border-[#e1dfd7] px-5 py-5 sm:px-6">
            <div>
                <div class="admin-kicker">{{ __('Latest runs') }}</div>
                <flux:heading id="recent-events" size="lg" class="mt-1">{{ __('Recent event ingestion activity') }}</flux:heading>
            </div>
            <flux:link :href="route('admin.event-sources.index')" class="font-semibold" wire:navigate>{{ __('View all') }}</flux:link>
        </div>

        @if ($hasEventRunsTable)
            <div class="overflow-x-auto px-3 pb-3 sm:px-5">
                <flux:table>
                    <flux:table.columns sticky>
                        <flux:table.column sticky>{{ __('Source') }}</flux:table.column>
                        <flux:table.column>{{ __('City') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Items written') }}</flux:table.column>
                        <flux:table.column>{{ __('Finished at') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($recentEventRuns as $run)
                            @php($source = $run->eventSource)
                            <flux:table.row :key="$run->id">
                                <flux:table.cell variant="strong" sticky>
                                    @if ($source)
                                        <flux:link :href="route('admin.event-sources.show', $source)" wire:navigate>
                                            {{ $source->name ?: __('Source :id', ['id' => $source->id]) }}
                                        </flux:link>
                                    @else
                                        {{ __('Source :id', ['id' => $run->event_source_id]) }}
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $source?->city?->name ?? __('Unknown') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$run->status === 'success' ? 'green' : ($run->status === 'failed' ? 'red' : 'yellow')" variant="subtle">
                                        {{ ucfirst($run->status) }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $run->items_written }}</flux:table.cell>
                                <flux:table.cell>{{ ($run->finished_at ?? $run->started_at)?->toDayDateTimeString() ?? __('Pending') }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5"><flux:text variant="subtle">{{ __('No event ingestion activity recorded yet.') }}</flux:text></flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        @else
            <flux:text variant="subtle" class="p-6">{{ __('Event ingestion runs not available yet.') }}</flux:text>
        @endif
    </section>
</div>
