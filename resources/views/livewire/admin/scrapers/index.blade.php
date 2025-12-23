<div class="space-y-6" wire:poll.10s>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Scrapers') }}</flux:heading>
            <flux:subheading>{{ __('Manage ingestion scrapers, filters, and manual runs.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.scrapers.create')" wire:navigate>
            {{ __('New Scraper') }}
        </flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5 items-end">
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

        <flux:field class="self-end xl:justify-self-end xl:pl-10">
            <flux:label class="sr-only">{{ __('Active only') }}</flux:label>

            <div class="h-11 flex items-center gap-3 justify-end pr-4">
                <flux:text class="text-sm font-medium leading-tight text-zinc-800 dark:text-zinc-100">
                    {{ __('Active only') }}
                </flux:text>

                <flux:switch wire:model.live="activeOnly" />
            </div>
        </flux:field>
    </div>

    <flux:card padding="lg" variant="subtle">
        <flux:table :paginate="$scrapers">
            <flux:table.columns sticky>
                <flux:table.column sticky>
                    <flux:table.sortable
                        :sorted="$sortField === 'scrapers.id'"
                        :direction="$sortDirection"
                        wire:click="sortBy('scrapers.id')"
                    >
                        <div>{{ __('ID') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column>
                    <flux:table.sortable
                        :sorted="$sortField === 'organization_name'"
                        :direction="$sortDirection"
                        wire:click="sortBy('organization_name')"
                    >
                        <div>{{ __('Organization') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column>
                    <flux:table.sortable
                        :sorted="$sortField === 'scrapers.type'"
                        :direction="$sortDirection"
                        wire:click="sortBy('scrapers.type')"
                    >
                        <div>{{ __('Type') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column>
                    <flux:table.sortable
                        :sorted="$sortField === 'scrapers.source_url'"
                        :direction="$sortDirection"
                        wire:click="sortBy('scrapers.source_url')"
                    >
                        <div>{{ __('Source URL') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column align="center">
                    <flux:table.sortable
                        :sorted="$sortField === 'scrapers.is_enabled'"
                        :direction="$sortDirection"
                        wire:click="sortBy('scrapers.is_enabled')"
                        class="justify-center"
                    >
                        <div>{{ __('Active') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column>{{ __('Last scraped') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($scrapers as $scraper)
                    @php
                        $lastRun = $scraper->latestRun;
                        $lastScraped = $lastRun?->finished_at ?? $lastRun?->started_at;
                        $latestStatus = $lastRun?->status;
                        $isActiveRun = in_array($latestStatus, ['queued', 'running'], true);
                    @endphp
                    <flux:table.row :key="$scraper->id">
                        <flux:table.cell variant="strong" sticky>#{{ $scraper->id }}</flux:table.cell>
                        <flux:table.cell>{{ $scraper->organization?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="indigo" variant="subtle" class="uppercase tracking-wide">
                                {{ $scraper->type }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($scraper->source_url)
                                <flux:link href="{{ $scraper->source_url }}" target="_blank">
                                    {{ \Illuminate\Support\Str::limit($scraper->source_url, 40) }}
                                </flux:link>
                            @else
                                <flux:text variant="subtle">{{ __('—') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="center">
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
                                @elseif ($latestStatus === 'failed')
                                    <flux:badge color="red" variant="subtle">{{ __('Failed') }}</flux:badge>
                                @elseif ($lastScraped)
                                    {{ $lastScraped->clone()->tz($tz)->diffForHumans() }}
                                @else
                                    <flux:text variant="subtle">{{ __('Never') }}</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="flex flex-wrap gap-2 justify-end">
                            <flux:button size="sm" variant="ghost" :href="route('admin.scrapers.show', $scraper)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" :href="route('admin.scrapers.edit', $scraper)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button
                                size="sm"
                                variant="primary"
                                wire:click="queueRun({{ $scraper->id }})"
                                wire:loading.attr="disabled"
                                wire:target="queueRun({{ $scraper->id }})"
                                :disabled="$isActiveRun"
                            >
                                <span class="inline-flex items-center justify-center w-12 h-8">
                                    @if ($isActiveRun)
                                        <flux:icon.loading class="size-4" />
                                    @else
                                        {{ __('Run') }}
                                    @endif
                                </span>
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <flux:text variant="subtle">{{ __('No scrapers match the current filters.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
