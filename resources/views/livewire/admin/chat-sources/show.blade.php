@php
    $latestRun = $source->latestRun;
    $latestStatus = $latestRun?->status;
    $lastRunAt = $latestRun?->finished_at ?? $latestRun?->started_at;
    $isActiveRun = $latestRun?->isFreshActive() ?? false;
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
            <flux:subheading>{{ __('Review chat source details and run it on demand.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" :href="route('admin.chat-sources.index')" wire:navigate>
                {{ __('Back to sources') }}
            </flux:button>
            <flux:button variant="ghost" :href="route('admin.chat-sources.edit', $source)" wire:navigate>
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
                        {{ __('Run now') }}
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
        <flux:card padding="xl" variant="subtle" class="space-y-4 lg:col-span-2">
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Source ID') }}</flux:text>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-white">#{{ $source->id }}</div>
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
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Frequency') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">{{ ucfirst($source->frequency ?? 'daily') }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Priority') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">{{ $source->priority }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Renderer') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">{{ strtoupper($source->crawl_renderer ?? 'auto') }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Link follow mode') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">{{ ucfirst($source->link_follow_mode ?? 'auto') }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Link limit') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">{{ $source->link_limit }}</div>
                </div>
                <div class="space-y-1 sm:col-span-2">
                    <flux:text variant="subtle">{{ __('Source URL') }}</flux:text>
                    <flux:link href="{{ $source->source_url }}" target="_blank" class="text-sm break-all">
                        {{ $source->source_url }}
                    </flux:link>
                </div>
            </div>

            <flux:separator />

            <div class="grid gap-6 sm:grid-cols-4">
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
                    <flux:text variant="subtle">{{ __('Pages found') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">{{ $latestRun?->pages_found ?? '—' }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Pages changed / embedded') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">
                        @if ($latestRun)
                            {{ "{$latestRun->pages_changed} / {$latestRun->pages_embedded}" }}
                        @else
                            {{ __('—') }}
                        @endif
                    </div>
                </div>
            </div>
        </flux:card>

        <flux:card padding="xl" variant="subtle" class="space-y-3">
            <flux:heading size="lg">{{ __('Description') }}</flux:heading>
            @if ($source->description)
                <flux:text>{{ $source->description }}</flux:text>
            @else
                <flux:text variant="subtle">{{ __('No description provided.') }}</flux:text>
            @endif

            <flux:separator />

            <flux:heading size="sm">{{ __('Tags') }}</flux:heading>
            <div class="flex flex-wrap gap-2">
                @forelse ($source->tags ?? [] as $tag)
                    <flux:badge color="zinc" variant="subtle">{{ $tag }}</flux:badge>
                @empty
                    <flux:text variant="subtle">{{ __('No tags') }}</flux:text>
                @endforelse
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
                <flux:table.column align="end">{{ __('Pages found') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Pages changed') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Pages embedded') }}</flux:table.column>
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
                        <flux:table.cell>{{ $run->started_at ? $run->started_at->clone()->tz($tz)->format('M j, Y g:i A') : __('—') }}</flux:table.cell>
                        <flux:table.cell>{{ $run->finished_at ? $run->finished_at->clone()->tz($tz)->format('M j, Y g:i A') : __('—') }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $run->pages_found }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $run->pages_changed }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $run->pages_embedded }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
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
                    {{ __('Pages changed: :count', ['count' => $latestRun->pages_changed]) }}
                </flux:text>
            </flux:callout>
        @endif
    </flux:card>
</div>
