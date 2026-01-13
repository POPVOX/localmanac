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

<div class="space-y-6" @if($isActiveRun) wire:poll.3s @endif>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $source->name }}</flux:heading>
            <flux:subheading>{{ __('Review event source details and run it on demand.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" :href="route('admin.event-sources.index')" wire:navigate>
                {{ __('Back to event sources') }}
            </flux:button>
            <flux:button variant="ghost" :href="route('admin.event-sources.edit', $source)" wire:navigate>
                {{ __('Edit') }}
            </flux:button>
            <flux:button
                variant="{{ $source->is_active ? 'ghost' : 'primary' }}"
                wire:click="toggleActive"
                wire:loading.attr="disabled"
                wire:target="toggleActive"
            >
                <span wire:loading.remove wire:target="toggleActive">{{ $source->is_active ? __('Deactivate') : __('Activate') }}</span>
                <span wire:loading.flex wire:target="toggleActive" class="items-center gap-2">
                    <flux:icon.loading class="size-4" />
                    {{ __('Updating...') }}
                </span>
            </flux:button>
            <flux:button
                variant="primary"
                wire:click="queueRun"
                wire:loading.attr="disabled"
                wire:target="queueRun"
                :disabled="$isActiveRun || ! $source->is_active"
            >
                <span wire:loading.remove wire:target="queueRun">
                    @if ($latestStatus === 'queued')
                        {{ __('Queued...') }}
                    @elseif ($latestStatus === 'running')
                        {{ __('Running...') }}
                    @else
                        {{ __('Run source now') }}
                    @endif
                </span>
                <span wire:loading.flex wire:target="queueRun" class="items-center gap-2">
                    <flux:icon.loading class="size-4" />
                    {{ __('Queueing...') }}
                </span>
            </flux:button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card padding="xl" variant="subtle" class="lg:col-span-2 space-y-4">
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Source ID') }}</flux:text>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-white">#{{ $source->id }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Type') }}</flux:text>
                    <flux:badge color="indigo" variant="subtle" class="uppercase">
                        {{ strtoupper(str_replace('_', ' ', $source->source_type)) }}
                    </flux:badge>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('City') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">{{ $source->city?->name ?? __('Unknown') }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Status') }}</flux:text>
                    <div>
                        <flux:badge :color="$source->is_active ? 'green' : 'red'">
                            {{ $source->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </div>
                </div>
                <div class="space-y-1 sm:col-span-2">
                    <flux:text variant="subtle">{{ __('Source URL') }}</flux:text>
                    @if ($source->source_url)
                        <flux:link href="{{ $source->source_url }}" target="_blank" class="text-sm break-all">
                            {{ $source->source_url }}
                        </flux:link>
                    @else
                        <flux:text variant="subtle">{{ __('—') }}</flux:text>
                    @endif
                </div>
            </div>

            <flux:separator />

            <div class="grid gap-6 sm:grid-cols-3">
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Last run status') }}</flux:text>
                    <div class="flex items-center gap-2 font-medium text-zinc-900 dark:text-white">
                        @if ($latestStatus)
                            @if ($isActiveRun)
                                <flux:icon.loading class="size-4 text-indigo-600" />
                            @endif
                            <flux:badge :color="$statusColor" variant="subtle" class="capitalize">
                                {{ $latestStatus }}
                            </flux:badge>
                        @else
                            <flux:text variant="subtle">{{ __('Never run') }}</flux:text>
                        @endif
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Last run at') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">
                        {{ $lastRunAt ? $lastRunAt->clone()->tz($tz)->format('M j, Y g:i A') : __('Never') }}
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Items (found / written)') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">
                        @if ($latestRun)
                            {{ "{$latestRun->items_found} / {$latestRun->items_written}" }}
                        @else
                            {{ __('—') }}
                        @endif
                    </div>
                </div>
            </div>
        </flux:card>

        <flux:card padding="xl" variant="subtle">
            <flux:heading size="lg">{{ __('Config') }}</flux:heading>
            <flux:text variant="subtle" class="mb-3">{{ __('Current JSON config (read only).') }}</flux:text>
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs font-mono leading-relaxed dark:border-zinc-700 dark:bg-zinc-800">
                <pre class="whitespace-pre-wrap break-words">{{ $configPreview }}</pre>
            </div>
        </flux:card>
    </div>

    <flux:card padding="xl" variant="subtle" class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="lg">{{ __('Latest runs') }}</flux:heading>
                <flux:text variant="subtle">{{ __('Queued and running states refresh automatically.') }}</flux:text>
            </div>
            <flux:badge :color="$statusColor" variant="subtle" class="capitalize">
                {{ $latestStatus ? ucfirst($latestStatus) : __('Never run') }}
            </flux:badge>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Started') }}</flux:table.column>
                <flux:table.column>{{ __('Finished') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Items found') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Items written') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($runs as $run)
                    @php
                        $runStatusColor = match ($run->status) {
                            'success' => 'green',
                            'running', 'queued' => 'amber',
                            'failed' => 'red',
                            default => 'zinc',
                        };
                    @endphp
                    <flux:table.row :key="$run->id">
                        <flux:table.cell>
                            <flux:badge :color="$runStatusColor" variant="subtle" class="capitalize">
                                {{ $run->status ?? __('Unknown') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $run->started_at ? $run->started_at->clone()->tz($tz)->format('M j, Y g:i A') : __('—') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $run->finished_at ? $run->finished_at->clone()->tz($tz)->format('M j, Y g:i A') : __('—') }}
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $run->items_found ?? 0 }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $run->items_written ?? 0 }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <flux:text variant="subtle">{{ __('No runs yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if ($latestRun?->error_message)
            <flux:callout variant="danger" icon="x-circle" :heading="__('Ingestion failed')">
                <pre class="whitespace-pre-wrap text-sm">{{ $latestRun->error_message }}</pre>
            </flux:callout>
        @elseif ($latestStatus === 'success' && $latestRun)
            <flux:callout variant="success" icon="check-circle" :heading="__('Last run completed successfully')">
                <flux:text variant="subtle">
                    {{ __('Items written: :count', ['count' => $latestRun->items_written]) }}
                </flux:text>
            </flux:callout>
        @endif
    </flux:card>
</div>
