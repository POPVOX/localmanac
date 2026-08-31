<div class="admin-page">
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Source operations') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">
                {{ __('Sources') }}
            </flux:heading>
            <flux:subheading class="mt-2 max-w-2xl">{{ __('One inventory for the feeds that publish articles, events, and trusted answers in every location.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.sources.create', array_filter(['cityId' => $cityId]))" icon="plus" wire:navigate>
            {{ __('Add source') }}
        </flux:button>
    </div>

    <section class="grid gap-3 md:grid-cols-3" aria-label="{{ __('Source categories') }}">
        @foreach ([
            ['kind' => 'article', 'label' => __('Article feeds'), 'count' => $categoryCounts['article'], 'icon' => 'newspaper', 'route' => 'admin.scrapers.index'],
            ['kind' => 'event', 'label' => __('Event calendars'), 'count' => $categoryCounts['event'], 'icon' => 'calendar-days', 'route' => 'admin.event-sources.index'],
            ['kind' => 'chat', 'label' => __('Answer library'), 'count' => $categoryCounts['chat'], 'icon' => 'chat-bubble-left-right', 'route' => 'admin.chat-sources.index'],
        ] as $category)
            <a
                href="{{ route($category['route'], array_filter(['cityId' => $cityId])) }}"
                class="admin-panel group flex items-center justify-between gap-4 p-4 transition hover:-translate-y-0.5 hover:border-[#aebfb6]"
                wire:navigate
            >
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-xl bg-[#e7f0eb] text-[#1f654f]"><flux:icon :icon="$category['icon']" class="size-5" /></span>
                    <div>
                        <div class="text-sm font-semibold text-[#18342c]">{{ $category['label'] }}</div>
                        <div class="mt-0.5 text-xs text-[#667970]">{{ trans_choice(':count source|:count sources', $category['count'], ['count' => $category['count']]) }}</div>
                    </div>
                </div>
                <flux:icon icon="chevron-right" class="size-4 text-[#87968f] transition group-hover:translate-x-0.5" />
            </a>
        @endforeach
    </section>

    <div class="admin-filter-panel grid items-end gap-4 md:grid-cols-2 xl:grid-cols-[minmax(260px,1.6fr)_minmax(180px,1fr)_minmax(160px,0.8fr)_auto]">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search sources')" placeholder="{{ __('Name, URL, city') }}" />

        <flux:select wire:model.live="cityId" :label="__('Location')">
            <option value="">{{ __('All locations') }}</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="kind" :label="__('Purpose')">
            <option value="">{{ __('All purposes') }}</option>
            <option value="article">{{ __('Articles') }}</option>
            <option value="event">{{ __('Events') }}</option>
            <option value="chat">{{ __('Answers') }}</option>
        </flux:select>

        <label class="flex h-11 items-center gap-3 self-end rounded-xl border border-[#d9d7ce] bg-white px-3 text-sm font-medium text-[#344f46]">
            <flux:switch wire:model.live="activeOnly" />
            {{ __('Active only') }}
        </label>
    </div>

    @if ($attentionCount > 0)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="trans_choice(':count source needs attention|:count sources need attention', $attentionCount, ['count' => $attentionCount])">
            {{ __('Open a source to review its latest failure, or use the verified repair proposal when one is available.') }}
        </flux:callout>
    @endif

    <section class="admin-panel overflow-hidden" aria-labelledby="source-inventory-heading">
        <div class="flex items-center justify-between border-b border-[#e1dfd7] px-5 py-4 sm:px-6">
            <div>
                <div class="admin-kicker">{{ __('Inventory') }}</div>
                <flux:heading id="source-inventory-heading" size="lg" class="mt-1">{{ __('All configured sources') }}</flux:heading>
            </div>
            <flux:text variant="subtle">{{ trans_choice(':count result|:count results', $sources->total(), ['count' => $sources->total()]) }}</flux:text>
        </div>

        <div class="overflow-x-auto px-3 pb-3 sm:px-5">
            <flux:table :paginate="$sources">
                <flux:table.columns sticky>
                    <flux:table.column sticky>{{ __('Source') }}</flux:table.column>
                    <flux:table.column>{{ __('Purpose') }}</flux:table.column>
                    <flux:table.column>{{ __('Location · preview') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($sources as $source)
                        @php
                            $kindColor = match ($source['kind']) {
                                'article' => 'blue',
                                'event' => 'purple',
                                default => 'zinc',
                            };
                            $healthColor = match ($source['health_status']) {
                                'healthy' => 'green',
                                'unhealthy' => 'amber',
                                default => 'zinc',
                            };
                            $deleteConfirmation = match ($source['kind']) {
                                'article' => __('Delete this article source? Its setup and run history will be removed. Published articles will remain.'),
                                'event' => __('Delete this event source? Its setup and run history will be removed. Published events will remain.'),
                                default => __('Delete this answer source? Its setup, run history, and indexed answer pages will be permanently removed.'),
                            };
                        @endphp
                        <flux:table.row :key="$source['key']">
                            <flux:table.cell variant="strong" sticky>
                                <div class="max-w-[420px] space-y-1">
                                    <flux:link :href="$source['show_route']" wire:navigate>{{ $source['name'] }}</flux:link>
                                    <div class="truncate text-xs font-normal text-[#718078]" title="{{ $source['source_url'] }}">{{ $source['source_url'] }}</div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell><flux:badge :color="$kindColor" variant="subtle">{{ $source['label'] }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                <x-admin.city-preview-link :name="$source['city_name']" :slug="$source['city_slug']" compact />
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <span class="size-2 rounded-full {{ $source['active'] ? 'bg-emerald-500' : 'bg-zinc-300' }}"></span>
                                    <span class="text-sm">{{ $source['active'] ? __('Active') : __('Paused') }}</span>
                                    @if ($source['health_status'] !== 'unknown')
                                        <flux:badge :color="$healthColor" variant="subtle" size="sm">{{ $source['health_status'] === 'unhealthy' ? __('Attention') : __('Healthy') }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" :href="$source['edit_route']" wire:navigate>{{ __('Edit') }}</flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-path"
                                        wire:click="retrySource('{{ $source['kind'] }}', {{ $source['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="retrySource('{{ $source['kind'] }}', {{ $source['id'] }})"
                                    >
                                        <span wire:loading.remove wire:target="retrySource('{{ $source['kind'] }}', {{ $source['id'] }})">{{ __('Retry') }}</span>
                                        <span wire:loading wire:target="retrySource('{{ $source['kind'] }}', {{ $source['id'] }})">{{ __('Queueing…') }}</span>
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        icon="trash"
                                        aria-label="{{ __('Delete :name', ['name' => $source['name']]) }}"
                                        wire:click="deleteSource('{{ $source['kind'] }}', {{ $source['id'] }})"
                                        wire:confirm="{{ $deleteConfirmation }}"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteSource('{{ $source['kind'] }}', {{ $source['id'] }})"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5"><flux:text variant="subtle">{{ __('No sources match this view.') }}</flux:text></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </section>
</div>
