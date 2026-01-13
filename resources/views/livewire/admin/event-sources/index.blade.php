<div class="space-y-6" wire:poll.10s>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Event Sources') }}</flux:heading>
            <flux:subheading>{{ __('Manage calendar ingestion sources and manual runs.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" :href="route('admin.event-sources.create')" wire:navigate>
            {{ __('New Event Source') }}
        </flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5 items-end">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            placeholder="{{ __('Name, source URL, city') }}"
            class="md:col-span-2 xl:col-span-2"
        />

        <flux:select wire:model.live="cityId" :label="__('City')">
            <option value="">{{ __('All cities') }}</option>
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

    <flux:card padding="lg" variant="subtle">
        <flux:table :paginate="$sources">
            <flux:table.columns sticky>
                <flux:table.column sticky>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('City') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column align="center">{{ __('Active') }}</flux:table.column>
                <flux:table.column>{{ __('Last run') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($sources as $source)
                    @php
                        $latestRun = $source->latestRun;
                        $latestStatus = $latestRun?->status;
                        $lastRunAt = $latestRun?->finished_at ?? $latestRun?->started_at;
                        $isActiveRun = in_array($latestStatus, ['queued', 'running'], true);
                        $tz = $source->city?->timezone ?? config('app.timezone', 'UTC');
                        $statusColor = match ($latestStatus) {
                            'success' => 'green',
                            'running', 'queued' => 'amber',
                            'failed' => 'red',
                            default => 'zinc',
                        };
                    @endphp
                    <flux:table.row :key="$source->id">
                        <flux:table.cell variant="strong" sticky>
                            {{ $source->name ?: __('Source :id', ['id' => $source->id]) }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $source->city?->name ?? __('Unknown') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="indigo" variant="subtle" class="uppercase tracking-wide">
                                {{ strtoupper(str_replace('_', ' ', $source->source_type)) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="center">
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
                                @elseif ($latestStatus === 'failed')
                                    <flux:badge color="red" variant="subtle">{{ __('Failed') }}</flux:badge>
                                @elseif ($lastRunAt)
                                    {{ $lastRunAt->clone()->tz($tz)->diffForHumans() }}
                                @else
                                    <flux:text variant="subtle">{{ __('Never') }}</flux:text>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="flex flex-wrap gap-2 justify-end">
                            <flux:button size="sm" variant="ghost" :href="route('admin.event-sources.show', $source)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" :href="route('admin.event-sources.edit', $source)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button
                                size="sm"
                                variant="primary"
                                wire:click="queueRun({{ $source->id }})"
                                wire:loading.attr="disabled"
                                wire:target="queueRun({{ $source->id }})"
                                :disabled="! $source->is_active || $isActiveRun"
                            >
                                <span class="inline-flex items-center justify-center w-16 h-8">
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
                        <flux:table.cell colspan="6">
                            <flux:text variant="subtle">{{ __('No event sources match the current filters.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
