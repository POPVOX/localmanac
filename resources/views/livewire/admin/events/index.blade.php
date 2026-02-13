<div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Events') }}</flux:heading>
            <flux:subheading>{{ __('Search, sort, and inspect ingested events by city, source, and date range.') }}</flux:subheading>
        </div>
    </div>

    <div class="grid gap-4 items-end md:grid-cols-2 xl:grid-cols-6">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            placeholder="{{ __('Title, city, source') }}"
            class="md:col-span-2 xl:col-span-2"
        />

        <flux:select wire:model.live="cityId" :label="__('City')">
            <option value="">{{ __('All cities') }}</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="sourceId" :label="__('Source')">
            <option value="">{{ __('All sources') }}</option>
            @foreach ($sources as $source)
                <option value="{{ $source->id }}">{{ $source->name }}</option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model.live="startDate"
            :label="__('Start date')"
            type="date"
        />

        <flux:input
            wire:model.live="endDate"
            :label="__('End date')"
            type="date"
        />
    </div>

    <flux:card padding="lg" variant="subtle">
        <flux:table :paginate="$events">
            <flux:table.columns sticky>
                <flux:table.column sticky class="w-[320px]">
                    <flux:table.sortable
                        :sorted="$sortField === 'events.title'"
                        :direction="$sortDirection"
                        wire:click="sortBy('events.title')"
                    >
                        <div>{{ __('Title') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column class="w-[120px]">{{ __('City') }}</flux:table.column>
                <flux:table.column class="w-[200px]">
                    <flux:table.sortable
                        :sorted="$sortField === 'events.starts_at'"
                        :direction="$sortDirection"
                        wire:click="sortBy('events.starts_at')"
                    >
                        <div>{{ __('Starts') }}</div>
                    </flux:table.sortable>
                </flux:table.column>
                <flux:table.column class="max-w-[240px]">{{ __('Location') }}</flux:table.column>
                <flux:table.column class="max-w-[180px]">{{ __('Source') }}</flux:table.column>
                <flux:table.column align="end" class="min-w-[140px]">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($events as $event)
                    @php
                        $tz = $event->city?->timezone ?? config('app.timezone', 'UTC');
                        $sourceItem = $event->sourceItems->first();
                        $source = $sourceItem?->eventSource;
                        $startLabel = $event->starts_at ? $event->starts_at->clone()->shiftTimezone($tz)->format('M j, Y') : null;
                    @endphp
                    <flux:table.row :key="$event->id">
                        <flux:table.cell variant="strong" sticky class="w-[320px]">
                            @php
                                $eventTitle = $event->title
                                    ? html_entity_decode($event->title, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                                    : __('Event :id', ['id' => $event->id]);
                            @endphp
                            <div class="w-[320px] truncate">
                                <flux:link :href="route('admin.events.show', $event)" wire:navigate class="block truncate" title="{{ $eventTitle }}">
                                    {{ $eventTitle }}
                                </flux:link>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $event->city?->name ?? __('Unknown') }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $startLabel ?? __('—') }}
                        </flux:table.cell>
                        <flux:table.cell class="max-w-[240px]">
                            <div class="truncate" title="{{ $event->location_name ?: __('—') }}">
                                {{ $event->location_name ?: __('—') }}
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="max-w-[180px]">
                            <div class="truncate" title="{{ $source?->name ?? __('—') }}">
                                {{ $source?->name ?? __('—') }}
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="min-w-[140px] flex flex-nowrap gap-2 justify-end whitespace-nowrap">
                            <flux:button size="sm" variant="ghost" :href="route('admin.events.show', $event)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" :href="route('admin.events.edit', $event)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <flux:text variant="subtle">{{ __('No events match the current filters.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
