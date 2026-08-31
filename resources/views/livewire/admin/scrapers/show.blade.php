@php
    $latestRun = $scraper->latestRun;
    $latestStatus = $latestRun?->status;
    $lastScraped = $latestRun?->finished_at ?? $latestRun?->started_at;
    $isActiveRun = $latestRun?->isFreshActive() ?? false;
    $sourceNeedsUpdate = $scraper->health_status === 'unhealthy' || ($latestRun?->sourceNeedsUpdate() ?? false);
    $sourceIssueSummary = $scraper->health_error ?: $latestRun?->sourceIssueSummary();
    $tz = $scraper->city?->timezone ?? config('app.timezone', 'UTC');
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
            <flux:heading size="xl" level="1">{{ $scraper->name }}</flux:heading>
            <flux:subheading>{{ __('Review scraper details and run it on demand.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" :href="route('admin.scrapers.index')" wire:navigate>
                {{ __('Back to scrapers') }}
            </flux:button>
            <flux:button variant="ghost" :href="route('admin.scrapers.edit', $scraper)" wire:navigate>
                {{ __('Edit') }}
            </flux:button>
            <flux:button
                variant="danger"
                icon="trash"
                wire:click="deleteSource"
                wire:confirm="{{ __('Delete this article source? Its setup and run history will be removed. Published articles will remain.') }}"
                wire:loading.attr="disabled"
                wire:target="deleteSource"
            >
                {{ __('Delete') }}
            </flux:button>
            <flux:button
                variant="{{ $scraper->is_enabled ? 'ghost' : 'primary' }}"
                wire:click="toggleActive"
                wire:loading.attr="disabled"
                wire:target="toggleActive"
            >
                <span wire:loading.remove wire:target="toggleActive">{{ $scraper->is_enabled ? __('Deactivate') : __('Activate') }}</span>
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
                :disabled="$isActiveRun"
            >
                <span wire:loading.remove wire:target="queueRun">
                    @if ($latestStatus === 'queued')
                        {{ __('Queued...') }}
                    @elseif ($latestStatus === 'running')
                        {{ __('Running...') }}
                    @elseif ($latestStatus === 'failed')
                        {{ __('Retry now') }}
                    @else
                        {{ __('Run scraper now') }}
                    @endif
                </span>
                <span wire:loading.flex wire:target="queueRun" class="items-center gap-2">
                    <flux:icon.loading class="size-4" />
                    {{ __('Queueing...') }}
                </span>
            </flux:button>
        </div>
    </div>

    @if ($sourceNeedsUpdate)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Source may no longer be valid')">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:text>{{ $sourceIssueSummary }}</flux:text>
                    @if ($scraper->repair_proposal)
                        <flux:text variant="subtle" class="mt-1">{{ $scraper->repair_proposal['summary'] ?? __('A verified repair is ready on the scraper list.') }}</flux:text>
                    @endif
                </div>
                <flux:button
                    size="sm"
                    variant="primary"
                    :href="route('admin.scrapers.edit', $scraper)"
                    wire:navigate
                >
                    {{ __('Update scraper') }}
                </flux:button>
            </div>
        </flux:callout>
    @endif

    <div class="grid gap-4">
        <flux:card padding="xl" variant="subtle" class="space-y-4">
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Scraper ID') }}</flux:text>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-white">#{{ $scraper->id }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('City') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">{{ $scraper->city?->name ?? __('Unknown') }}</div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Organization') }}</flux:text>
                    <div class="text-lg font-medium text-zinc-900 dark:text-white">
                        {{ $scraper->organization?->name ?? __('Unassigned') }}
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Source URL') }}</flux:text>
                    @if ($scraper->source_url)
                        <flux:link href="{{ $scraper->source_url }}" target="_blank" class="text-sm break-all">
                            {{ $scraper->source_url }}
                        </flux:link>
                    @else
                        <flux:text variant="subtle">{{ __('—') }}</flux:text>
                    @endif
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Status') }}</flux:text>
                    <div>
                        <flux:badge :color="$scraper->is_enabled ? 'green' : 'red'">
                            {{ $scraper->is_enabled ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </div>
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
                    <flux:text variant="subtle">{{ __('Last scraped at') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">
                        {{ $lastScraped ? $lastScraped->clone()->tz($tz)->format('M j, Y g:i A') : __('Never') }}
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Items (found / created / updated)') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">
                        @if ($latestRun)
                            {{ "{$latestRun->items_found} / {$latestRun->items_created} / {$latestRun->items_updated}" }}
                        @else
                            {{ __('—') }}
                        @endif
                    </div>
                </div>
            </div>
        </flux:card>

        <details class="admin-panel group p-5">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-semibold text-[#18342c]">
                <span>{{ __('Advanced source details') }}</span>
                <flux:icon icon="chevron-down" class="size-4 transition group-open:rotate-180" />
            </summary>
            <div class="mt-5 grid gap-5 border-t border-[#e1dfd7] pt-5 lg:grid-cols-[180px_minmax(0,1fr)]">
                <div>
                    <flux:text variant="subtle">{{ __('Source type') }}</flux:text>
                    <div class="mt-2"><flux:badge color="indigo" variant="subtle" class="uppercase">{{ $scraper->type }}</flux:badge></div>
                </div>
                <div>
                    <flux:text variant="subtle" class="mb-3">{{ __('Current JSON config (read only).') }}</flux:text>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs font-mono leading-relaxed dark:border-zinc-700 dark:bg-zinc-800">
                        <pre class="whitespace-pre-wrap break-words">{{ $configPreview }}</pre>
                    </div>
                </div>
            </div>
        </details>
    </div>

    <flux:card padding="xl" variant="subtle" class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="lg">{{ __('Latest run') }}</flux:heading>
                <flux:text variant="subtle">{{ __('Queued and running states refresh automatically.') }}</flux:text>
            </div>
            <flux:badge :color="$statusColor" variant="subtle" class="capitalize">
                {{ $latestStatus ? ucfirst($latestStatus) : __('Never run') }}
            </flux:badge>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Started at') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $latestRun?->started_at ? $latestRun->started_at->clone()->tz($tz)->format('M j, Y g:i A') : __('—') }}
                </div>
            </div>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Finished at') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $latestRun?->finished_at ? $latestRun->finished_at->clone()->tz($tz)->format('M j, Y g:i A') : __('—') }}
                </div>
            </div>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Items found') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $latestRun?->items_found ?? '—' }}
                </div>
            </div>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Items created') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $latestRun?->items_created ?? '—' }}
                </div>
            </div>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Items updated') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $latestRun?->items_updated ?? '—' }}
                </div>
            </div>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Skipped items') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    @if ($latestRun)
                        {{ $latestRun->meta['skipped_items'] ?? 0 }}
                    @else
                        {{ __('—') }}
                    @endif
                </div>
            </div>
        </div>

        @if ($latestRun?->error_message)
            <flux:callout variant="danger" icon="x-circle" :heading="__('Scrape failed')">
                <pre class="whitespace-pre-wrap text-sm">{{ $latestRun->error_message }}</pre>
            </flux:callout>
        @elseif ($latestStatus === 'success' && $latestRun)
            <flux:callout variant="success" icon="check-circle" :heading="__('Last run completed successfully')">
                <flux:text variant="subtle">
                    {{ __('Items found: :count', ['count' => $latestRun->items_found]) }}
                </flux:text>
            </flux:callout>
        @endif
    </flux:card>
</div>
