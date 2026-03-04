<div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Chat Sources') }}</flux:heading>
            <flux:subheading>{{ __('Curated sources used for answering questions.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.chat-sources.create')" wire:navigate>
            {{ __('New Source') }}
        </flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5 items-end">
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

        <flux:field class="self-end md:col-start-2 md:justify-self-end xl:col-start-5 xl:justify-self-end xl:pl-10">
            <flux:label class="sr-only">{{ __('Active only') }}</flux:label>

            <div class="h-11 flex items-center gap-3 justify-end pr-4">
                <flux:text class="text-sm font-medium leading-tight text-zinc-800 dark:text-zinc-100">
                    {{ __('Active only') }}
                </flux:text>

                <flux:switch wire:model.live="activeOnly" />
            </div>
        </flux:field>
    </div>

    <flux:card padding="lg" variant="subtle" class="space-y-4 bg-zinc-100/60 dark:bg-zinc-800/45">
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

        <div class="space-y-2">
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
    </flux:card>

    <flux:card padding="lg" variant="subtle" class="bg-zinc-100/60 dark:bg-zinc-800/45">
        <flux:table :paginate="$sources">
            <flux:table.columns sticky>
                <flux:table.column>
                    <flux:table.sortable
                        :sorted="$sortField === 'chat_sources.name'"
                        :direction="$sortDirection"
                        wire:click="sortBy('chat_sources.name')"
                    >
                        <div>{{ __('Name') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column>{{ __('City') }}</flux:table.column>
                <flux:table.column>{{ __('Source URL') }}</flux:table.column>
                <flux:table.column align="center">
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
                <flux:table.column>{{ __('Updated') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($sources as $source)
                    <flux:table.row :key="$source->id">
                        <flux:table.cell variant="strong">{{ $source->name }}</flux:table.cell>
                        <flux:table.cell>{{ $source->city?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:link href="{{ $source->source_url }}" target="_blank">
                                {{ \Illuminate\Support\Str::limit($source->source_url, 40) }}
                            </flux:link>
                        </flux:table.cell>
                        <flux:table.cell align="center">{{ $source->priority }}</flux:table.cell>
                        <flux:table.cell class="w-[112px]">
                            <flux:switch
                                :checked="$source->is_active"
                                wire:click="toggleActive({{ $source->id }})"
                                aria-label="{{ __('Toggle active') }}"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $source->updated_at?->diffForHumans() ?? '—' }}
                        </flux:table.cell>
                        <flux:table.cell align="end" class="flex flex-wrap gap-2 justify-end">
                            <flux:button size="sm" variant="ghost" :href="route('admin.chat-sources.edit', $source)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <flux:text variant="subtle">{{ __('No sources match the current filters.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
