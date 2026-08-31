<div class="admin-page" wire:poll.10s>
    <div class="admin-page-header">
        <div>
            <div class="admin-kicker">{{ __('Article ingestion') }}</div>
            <flux:heading size="xl" level="1" class="mt-2 font-serif !text-4xl !font-medium tracking-[-0.03em] text-[#123e32]">{{ __('Scrapers') }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('Manage article sources, review health, and run imports.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.sources.create')" icon="plus" wire:navigate>
            {{ __('Add source') }}
        </flux:button>
    </div>

    <div class="admin-filter-panel grid items-end gap-4 md:grid-cols-2 xl:grid-cols-5">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            placeholder="{{ __('Name, slug, source, organization') }}"
            class="md:col-span-2 xl:col-span-2"
        />
        <flux:select wire:model.live="cityId" :label="__('City')">
            <option value="">{{ __('All cities') }}</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="organizationId" :label="__('Organization')">
            <option value="">{{ __('All organizations') }}</option>
            @foreach ($organizations as $organization)
                <option value="{{ $organization->id }}">{{ $organization->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="type" :label="__('Type')">
            <option value="">{{ __('All types') }}</option>
            @foreach ($types as $typeOption)
                <option value="{{ $typeOption }}">{{ strtoupper($typeOption) }}</option>
            @endforeach
        </flux:select>

        <flux:field class="self-end xl:justify-self-end">
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
        <flux:table :paginate="$scrapers">
            <flux:table.columns sticky>
                <flux:table.column sticky class="w-[420px]">
                    <flux:table.sortable
                        :sorted="$sortField === 'scrapers.name'"
                        :direction="$sortDirection"
                        wire:click="sortBy('scrapers.name')"
                    >
                        <div>{{ __('Scraper') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column class="w-[112px]">{{ __('Active') }}</flux:table.column>
                <flux:table.column>{{ __('Last scraped') }}</flux:table.column>
                <flux:table.column align="end" class="w-[84px] min-w-[84px]">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($scrapers as $scraper)
                    @php
                        $lastRun = $scraper->latestRun;
                        $lastScraped = $lastRun?->finished_at ?? $lastRun?->started_at;
                        $latestStatus = $lastRun?->status;
                        $isActiveRun = $lastRun?->isFreshActive() ?? false;
                        $sourceNeedsUpdate = $scraper->health_status === 'unhealthy' || ($lastRun?->sourceNeedsUpdate() ?? false);
                        $repairProposal = is_array($scraper->repair_proposal) ? $scraper->repair_proposal : null;
                    @endphp
                    <flux:table.row :key="$scraper->id">
                        <flux:table.cell variant="strong" sticky class="w-[420px]">
                            <div class="space-y-1">
                                <div>{{ $scraper->name ?: __('Scraper :id', ['id' => $scraper->id]) }}</div>
                                <div class="flex items-center gap-2 overflow-hidden">
                                    <flux:text variant="subtle" class="shrink-0">#{{ $scraper->id }}</flux:text>
                                    <flux:badge color="indigo" variant="subtle" class="shrink-0 uppercase tracking-wide">
                                        {{ $scraper->type }}
                                    </flux:badge>
                                    <flux:text variant="subtle" class="truncate">
                                        {{ $scraper->organization?->name ?? __('Unassigned') }}
                                    </flux:text>
                                </div>
                                @if ($sourceNeedsUpdate)
                                    <div class="flex flex-wrap items-center gap-2 pt-1">
                                        <flux:badge color="amber" variant="subtle" icon="exclamation-triangle">
                                            {{ __('Source needs update') }}
                                        </flux:badge>
                                        <flux:link
                                            :href="route('admin.scrapers.edit', $scraper)"
                                            class="text-xs font-semibold"
                                            wire:navigate
                                        >
                                            {{ __('Update scraper') }}
                                        </flux:link>
                                    </div>
                                    @if ($repairProposal)
                                        <div class="text-xs leading-5 text-[#5d7168]">
                                            {{ $repairProposal['summary'] ?? __('A verified repair is ready to apply.') }}
                                        </div>
                                    @elseif ($scraper->health_error)
                                        <div class="line-clamp-2 text-xs leading-5 text-[#7c5c25]">{{ $scraper->health_error }}</div>
                                    @endif
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="w-[112px]">
                            <flux:switch
                                :checked="$scraper->is_enabled"
                                wire:click="toggleActive({{ $scraper->id }})"
                                aria-label="{{ __('Toggle active') }}"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            @php
                                $tz = $scraper->city?->timezone ?? config('app.timezone', 'UTC');
                            @endphp
                            <div class="flex items-center gap-2">
                                @if ($isActiveRun)
                                    <flux:text variant="subtle">
                                        {{ $latestStatus === 'queued' ? __('Queued') : __('Running') }}
                                    </flux:text>
                                @elseif ($sourceNeedsUpdate)
                                    <flux:badge color="amber" variant="subtle">{{ __('Source invalid') }}</flux:badge>
                                @elseif ($latestStatus === 'failed')
                                    <flux:badge color="red" variant="subtle">{{ __('Failed') }}</flux:badge>
                                @elseif ($lastScraped)
                                    {{ $lastScraped->clone()->tz($tz)->diffForHumans() }}
                                @else
                                    <flux:text variant="subtle">{{ __('Never') }}</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="w-[84px] min-w-[84px] pr-2">
                            <div class="flex justify-end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('Scraper actions') }}" />

                                    <flux:menu class="w-[176px]">
                                        <flux:menu.item :href="route('admin.scrapers.show', $scraper)" icon="eye" wire:navigate>
                                            {{ __('View') }}
                                        </flux:menu.item>
                                        <flux:menu.item :href="route('admin.scrapers.edit', $scraper)" icon="pencil-square" wire:navigate>
                                            {{ $sourceNeedsUpdate ? __('Update scraper') : __('Edit') }}
                                        </flux:menu.item>
                                        @if ($repairProposal)
                                            <flux:menu.item wire:click="applyRepair({{ $scraper->id }})" icon="wrench-screwdriver">
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
                                                wire:click="queueRun({{ $scraper->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="queueRun({{ $scraper->id }})"
                                                icon="play"
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
                        <flux:table.cell colspan="4">
                            <flux:text variant="subtle">{{ __('No scrapers match the current filters.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
