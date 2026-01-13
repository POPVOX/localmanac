<div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Events') }}</flux:heading>
            <flux:subheading>{{ __('Inspect ingested events by city, source, and date range.') }}</flux:subheading>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6 items-end">
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

        <flux:field class="self-end xl:justify-self-end xl:pl-10">
            <flux:label class="sr-only">{{ __('Has URL only') }}</flux:label>

            <div class="h-11 flex items-center gap-3 justify-end pr-4">
                <flux:text class="text-sm font-medium leading-tight text-zinc-800 dark:text-zinc-100">
                    {{ __('Has URL only') }}
                </flux:text>

                <flux:switch wire:model.live="hasUrlOnly" />
            </div>
        </flux:field>
    </div>

    <flux:card padding="lg" variant="subtle">
        <flux:table :paginate="$events" class="table-fixed">
            <flux:table.columns sticky>
                <flux:table.column sticky class="w-[360px]">{{ __('Title') }}</flux:table.column>
                <flux:table.column class="w-[140px]">{{ __('City') }}</flux:table.column>
                <flux:table.column class="w-[220px]">{{ __('Starts') }}</flux:table.column>
                <flux:table.column class="max-w-[240px]">{{ __('Location') }}</flux:table.column>
                <flux:table.column class="max-w-[220px]">{{ __('Source') }}</flux:table.column>
                <flux:table.column align="end" class="min-w-[140px]">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($events as $event)
                    @php
                        $tz = $event->city?->timezone ?? config('app.timezone', 'UTC');
                        $sourceItem = $event->sourceItems->first();
                        $source = $sourceItem?->eventSource;
                        $startLabel = $event->starts_at ? $event->starts_at->clone()->shiftTimezone($tz)->format('M j, Y g:i A') : null;
                        if ($event->all_day && $event->starts_at) {
                            $startLabel = $event->starts_at->clone()->shiftTimezone($tz)->format('M j, Y');
                        }
                    @endphp
                    <flux:table.row :key="$event->id">
                        <flux:table.cell variant="strong" sticky class="w-[360px]">
                            @php
                                $eventTitle = $event->title ?: __('Event :id', ['id' => $event->id]);
                            @endphp
                            <div class="w-[360px] truncate">
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
                        <flux:table.cell class="max-w-[220px]">
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
