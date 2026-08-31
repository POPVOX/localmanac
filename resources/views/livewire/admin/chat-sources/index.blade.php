@php
    $hasActiveRuns = $sources->contains(fn ($source) => $source->latestRun?->isFreshActive() ?? false);
@endphp

<div class="admin-page" @if($hasActiveRuns) wire:poll.10s @endif>
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Answer library') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ __('Chat Sources') }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Manage the trusted sources used for local answers.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.chat-sources.create')" icon="plus" wire:navigate>
            {{ __('New Source') }}
        </flux:button>
    </div>

    <div class="admin-filter-panel grid items-end gap-4 md:grid-cols-2 xl:grid-cols-5">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            placeholder="{{ __('Name, URL, description') }}"
            class="md:col-span-2 xl:col-span-2"
        />
        <flux:select wire:model.live="cityId" :label="__('City')">
            <option value="">{{ __('All cities') }}</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </flux:select>

        <flux:field class="self-end md:col-start-2 md:justify-self-end xl:col-start-5 xl:justify-self-end">
            <flux:label class="sr-only">{{ __('Active only') }}</flux:label>

            <div class="flex h-11 items-center gap-3 rounded-xl border border-[#d9d7ce] bg-white px-3">
                <flux:text class="text-sm font-medium leading-tight text-zinc-800 dark:text-zinc-100">
                    {{ __('Active only') }}
                </flux:text>

                <flux:switch wire:model.live="activeOnly" />
            </div>
        </flux:field>
    </div>

    <flux:card padding="lg" class="admin-panel space-y-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">{{ __('Ingestion Summary') }}</flux:heading>
            <flux:text variant="subtle">{{ __('Last 24 hours') }}</flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card padding="md" class="space-y-1">
                <flux:text size="xs" class="uppercase tracking-wide text-zinc-500">{{ __('Total Pages') }}</flux:text>
                <flux:heading size="lg">{{ number_format($summary['total_pages']) }}</flux:heading>
            </flux:card>

            <flux:card padding="md" class="space-y-1">
                <flux:text size="xs" class="uppercase tracking-wide text-zinc-500">{{ __('Pages (24h)') }}</flux:text>
                <flux:heading size="lg">{{ number_format($summary['pages_last_24h']) }}</flux:heading>
            </flux:card>

            <flux:card padding="md" class="space-y-1">
                <flux:text size="xs" class="uppercase tracking-wide text-zinc-500">{{ __('Avg Fetch Time') }}</flux:text>
                <flux:heading size="lg">
                    {{ $summary['avg_fetch_ms'] ? number_format($summary['avg_fetch_ms']).' ms' : '—' }}
                </flux:heading>
            </flux:card>

            <flux:card padding="md" class="space-y-1">
                <flux:text size="xs" class="uppercase tracking-wide text-zinc-500">{{ __('Playwright Share') }}</flux:text>
                <flux:heading size="lg">
                    {{ $summary['playwright_rate'] }}%
                </flux:heading>
                <flux:text size="sm" variant="subtle">
                    {{ number_format($summary['playwright_pages']) }} {{ __('pages') }}
                </flux:text>
            </flux:card>
        </div>

        <details class="group rounded-lg border border-zinc-200 bg-zinc-50/50 p-3 dark:border-zinc-700 dark:bg-zinc-900/30">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-zinc-700 marker:content-none dark:text-zinc-200">
                <span class="group-open:hidden">{{ __('Show slowest sources') }}</span>
                <span class="hidden group-open:inline">{{ __('Hide slowest sources') }}</span>
            </summary>

            <div class="mt-3 space-y-2">
                <flux:heading size="sm">{{ __('Slowest Sources (avg fetch)') }}</flux:heading>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Source') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Avg') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Pages') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Playwright') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Last fetched') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($slowSources as $source)
                            <flux:table.row :key="$source['id']">
                                <flux:table.cell variant="strong">
                                    {{ $source['name'] }}
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    {{ $source['avg_fetch_ms'] ? number_format($source['avg_fetch_ms']).' ms' : '—' }}
                                </flux:table.cell>
                                <flux:table.cell align="center">{{ number_format($source['page_count']) }}</flux:table.cell>
                                <flux:table.cell align="center">{{ number_format($source['playwright_pages']) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    {{ $source['last_fetched_at']?->diffForHumans() ?? '—' }}
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5">
                                    <flux:text variant="subtle">{{ __('No ingestion metrics yet.') }}</flux:text>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </details>
    </flux:card>

        <flux:card padding="lg" variant="subtle" class="bg-white dark:bg-zinc-800/35">
        <flux:table :paginate="$sources">
            <flux:table.columns sticky>
                <flux:table.column sticky class="w-[320px] min-w-[320px]">
                    <flux:table.sortable
                        :sorted="$sortField === 'chat_sources.name'"
                        :direction="$sortDirection"
                        wire:click="sortBy('chat_sources.name')"
                    >
                        <div>{{ __('Name') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column class="w-[140px] min-w-[140px]">{{ __('City') }}</flux:table.column>
                <flux:table.column align="center" class="w-[96px] min-w-[96px]">
                    <flux:table.sortable
                        :sorted="$sortField === 'chat_sources.priority'"
                        :direction="$sortDirection"
                        wire:click="sortBy('chat_sources.priority')"
                        class="justify-center"
                    >
                        <div>{{ __('Priority') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column class="w-[112px]">{{ __('Active') }}</flux:table.column>
                <flux:table.column class="w-[168px] min-w-[168px]">{{ __('Last run') }}</flux:table.column>
                <flux:table.column align="end" class="w-[84px] min-w-[84px]">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($sources as $source)
                    @php
                        $latestRun = $source->latestRun;
                        $latestStatus = $latestRun?->status;
                        $lastRunAt = $latestRun?->finished_at ?? $latestRun?->started_at;
                        $isActiveRun = $latestRun?->isFreshActive() ?? false;
                        $tz = $source->city?->timezone ?? config('app.timezone', 'UTC');
                    @endphp
                    <flux:table.row :key="$source->id">
                        <flux:table.cell variant="strong" sticky class="w-[320px] min-w-[320px]">
                            <div class="space-y-1">
                                <div>{{ $source->name }}</div>
                                <flux:link href="{{ $source->source_url }}" target="_blank" class="block truncate text-sm font-normal text-zinc-500">
                                    {{ $source->source_url }}
                                </flux:link>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $source->city?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="center">{{ $source->priority }}</flux:table.cell>
                        <flux:table.cell class="w-[112px]">
                            <flux:switch
                                :checked="$source->is_active"
                                wire:click="toggleActive({{ $source->id }})"
                                aria-label="{{ __('Toggle active') }}"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($isActiveRun)
                                <flux:text variant="subtle">
                                    {{ $latestStatus === 'queued' ? __('Queued') : __('Running') }}
                                </flux:text>
                            @elseif ($latestStatus === 'failed')
                                <flux:badge color="red" variant="subtle">{{ __('Failed') }}</flux:badge>
                            @elseif ($lastRunAt)
                                {{ $lastRunAt->clone()->tz($tz)->diffForHumans() }}
                            @else
                                <flux:text variant="subtle">{{ __('Never') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end" class="w-[84px] min-w-[84px] pr-2">
                            <div class="flex justify-end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('Source actions') }}" />

                                    <flux:menu class="w-[176px]">
                                        <flux:menu.item :href="route('admin.chat-sources.show', $source)" icon="eye" wire:navigate>
                                            {{ __('View') }}
                                        </flux:menu.item>
                                        <flux:menu.item :href="route('admin.chat-sources.edit', $source)" icon="pencil-square" wire:navigate>
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        @if ($isActiveRun)
                                            <flux:menu.item icon="arrow-path" class="pointer-events-none opacity-60">
                                                {{ $latestStatus === 'queued' ? __('Queued') : __('Running') }}
                                            </flux:menu.item>
                                        @else
                                            <flux:menu.item
                                                wire:click="queueRun({{ $source->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="queueRun({{ $source->id }})"
                                                icon="play"
                                                :class="! $source->is_active ? 'pointer-events-none opacity-60' : ''"
                                            >
                                                {{ __('Run') }}
                                            </flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <flux:text variant="subtle">{{ __('No sources match the current filters.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
