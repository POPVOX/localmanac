<div class="admin-page" wire:poll.10s>
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Calendar ingestion') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ __('Event Sources') }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Manage calendar sources, review health, and run imports.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.sources.create')" icon="plus" wire:navigate>
            {{ __('Add source') }}
        </flux:button>
    </div>

    <div class="admin-filter-panel grid items-end gap-4 md:grid-cols-2 xl:grid-cols-5">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            placeholder="{{ __('Name, source URL, city') }}"
            class="md:col-span-2 xl:col-span-2"
        />

        <flux:select wire:model.live="cityId" :label="__('Location')">
            <option value="">{{ __('All locations') }}</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="type" :label="__('Type')">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $typeOption)
                @php
                    $label = strtoupper(str_replace('_', ' ', $typeOption));
                @endphp
                <option value="{{ $typeOption }}">{{ $label }}</option>
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

    <flux:card padding="lg" class="admin-panel overflow-hidden">
        <flux:table :paginate="$sources">
            <flux:table.columns sticky>
                <flux:table.column sticky class="w-[380px]">{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Location · preview') }}</flux:table.column>
                <flux:table.column class="w-[112px]">{{ __('Active') }}</flux:table.column>
                <flux:table.column>{{ __('Last run') }}</flux:table.column>
                <flux:table.column align="end" class="w-[84px] min-w-[84px]">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($sources as $source)
                    @php
                        $latestRun = $source->latestRun;
                        $latestStatus = $latestRun?->status;
                        $lastRunAt = $latestRun?->finished_at ?? $latestRun?->started_at;
                        $isActiveRun = in_array($latestStatus, ['queued', 'running'], true);
                        $sourceNeedsUpdate = $source->health_status === 'unhealthy';
                        $repairProposal = is_array($source->repair_proposal) ? $source->repair_proposal : null;
                        $tz = $source->city?->timezone ?? config('app.timezone', 'UTC');
                        $statusColor = match ($latestStatus) {
                            'success' => 'green',
                            'running', 'queued' => 'amber',
                            'failed' => 'red',
                            default => 'zinc',
                        };
                    @endphp
                    <flux:table.row :key="$source->id">
                        <flux:table.cell variant="strong" sticky class="w-[380px]">
                            <div class="space-y-1">
                                <div>{{ $source->name ?: __('Source :id', ['id' => $source->id]) }}</div>
                                <div class="flex items-center gap-2 overflow-hidden">
                                    <flux:badge color="indigo" variant="subtle" class="shrink-0 uppercase tracking-wide">
                                        {{ strtoupper(str_replace('_', ' ', $source->source_type)) }}
                                    </flux:badge>
                                    @if ($source->source_url)
                                        <flux:link href="{{ $source->source_url }}" target="_blank" class="block truncate text-sm font-normal text-zinc-500">
                                            {{ $source->source_url }}
                                        </flux:link>
                                    @endif
                                </div>
                                @if ($sourceNeedsUpdate)
                                    <div class="flex flex-wrap items-center gap-2 pt-1">
                                        <flux:badge color="amber" variant="subtle" icon="exclamation-triangle">{{ __('Source needs update') }}</flux:badge>
                                        @if ($repairProposal)
                                            <span class="text-xs text-[#5d7168]">{{ $repairProposal['summary'] ?? __('A verified repair is ready.') }}</span>
                                        @elseif ($source->health_error)
                                            <span class="line-clamp-2 text-xs text-[#7c5c25]">{{ $source->health_error }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell><x-admin.city-preview-link :city="$source->city" compact /></flux:table.cell>
                        <flux:table.cell class="w-[112px]">
                            <flux:switch
                                :checked="$source->is_active"
                                wire:click="toggleActive({{ $source->id }})"
                                aria-label="{{ __('Toggle active') }}"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                @if ($isActiveRun)
                                    <flux:text variant="subtle">
                                        {{ $latestStatus === 'queued' ? __('Queued') : __('Running') }}
                                    </flux:text>
                                @elseif ($sourceNeedsUpdate)
                                    <flux:badge color="amber" variant="subtle">{{ __('Source invalid') }}</flux:badge>
                                @elseif ($latestStatus === 'failed')
                                    <flux:badge color="red" variant="subtle">{{ __('Failed') }}</flux:badge>
                                @elseif ($lastRunAt)
                                    {{ $lastRunAt->clone()->tz($tz)->diffForHumans() }}
                                @else
                                    <flux:text variant="subtle">{{ __('Never') }}</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="w-[84px] min-w-[84px] pr-2">
                            <div class="flex justify-end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('Source actions') }}" />

                                    <flux:menu class="w-[176px]">
                                        <flux:menu.item :href="route('admin.event-sources.show', $source)" icon="eye" wire:navigate>
                                            {{ __('View') }}
                                        </flux:menu.item>
                                        <flux:menu.item :href="route('admin.event-sources.edit', $source)" icon="pencil-square" wire:navigate>
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        @if ($repairProposal)
                                            <flux:menu.item wire:click="applyRepair({{ $source->id }})" icon="wrench-screwdriver">
                                                {{ __('Apply verified repair') }}
                                            </flux:menu.item>
                                        @endif
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
                        <flux:table.cell colspan="5">
                            <flux:text variant="subtle">{{ __('No event sources match the current filters.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
