@php
    $latestRun = $source?->latestRun;
    $latestStatus = $latestRun?->status;
    $lastRunAt = $latestRun?->finished_at ?? $latestRun?->started_at;
    $isActiveRun = $latestRun?->isFreshActive() ?? false;
    $tz = $source?->city?->timezone ?? config('app.timezone', 'UTC');
    $statusColor = match ($latestStatus) {
        'success' => 'green',
        'running', 'queued' => 'amber',
        'failed' => 'red',
        default => 'zinc',
    };
@endphp

<div class="space-y-6" @if($isActiveRun) wire:poll.3s @endif>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            <flux:subheading>{{ __('Add or edit curated sources for the chat assistant.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" :href="route('admin.chat-sources.index')" wire:navigate>
            {{ __('Back to sources') }}
        </flux:button>
    </div>

    @if ($source)
        <flux:card padding="lg" variant="subtle" class="space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <flux:heading size="lg">{{ __('Operations') }}</flux:heading>
                    <flux:text variant="subtle">{{ __('Queue a manual ingestion run or inspect recent status before editing settings.') }}</flux:text>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button variant="ghost" :href="route('admin.chat-sources.show', $source)" wire:navigate>
                        {{ __('View details') }}
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

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Last run status') }}</flux:text>
                    <div>
                        @if ($latestStatus)
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
                    <flux:text variant="subtle">{{ __('Pages (found / changed / embedded)') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">
                        @if ($latestRun)
                            {{ "{$latestRun->pages_found} / {$latestRun->pages_changed} / {$latestRun->pages_embedded}" }}
                        @else
                            {{ __('—') }}
                        @endif
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Scheduling') }}</flux:text>
                    <div class="font-medium text-zinc-900 dark:text-white">
                        {{ ucfirst($frequency) }}
                    </div>
                </div>
            </div>

            @if ($latestRun?->error_message)
                <flux:callout variant="danger" icon="x-circle" :heading="__('Last run failed')">
                    <pre class="whitespace-pre-wrap text-sm">{{ $latestRun->error_message }}</pre>
                </flux:callout>
            @endif
        </flux:card>
    @endif

    <flux:card padding="xl" variant="subtle" class="space-y-6">
        <form wire:submit.prevent="save" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model.live="cityId" :label="__('City')" required>
                    <option value="">{{ __('Select a city') }}</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model.live="name"
                    :label="__('Name')"
                    type="text"
                    required
                    autofocus
                />
            </div>

            <flux:input
                wire:model.live="sourceUrl"
                :label="__('Source URL')"
                type="url"
                required
            />

            <flux:textarea
                wire:model.live="description"
                :label="__('Description (optional)')"
                rows="4"
            />

            <div class="grid gap-4 md:grid-cols-3">
                <flux:input
                    wire:model.live="tags"
                    :label="__('Tags (comma separated)')"
                    placeholder="{{ __('trash pickup, permits, parks') }}"
                />

                <flux:input
                    wire:model.live="priority"
                    :label="__('Priority')"
                    type="number"
                    min="0"
                />

                <flux:select wire:model.live="frequency" :label="__('Frequency')">
                    @foreach ($frequencies as $frequencyOption)
                        <option value="{{ $frequencyOption }}">{{ ucfirst($frequencyOption) }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex items-center gap-3">
                <flux:switch wire:model.live="isActive" :label="__('Active')" />
                <flux:text variant="subtle">{{ __('Inactive sources will be skipped for chat answers.') }}</flux:text>
            </div>

            <div class="flex items-center justify-between gap-3">
                <flux:text class="font-medium">{{ __('Advanced') }}</flux:text>
                <flux:button type="button" variant="subtle" wire:click="toggleAdvanced">
                    {{ $showAdvanced ? __('Hide') : __('Show') }}
                </flux:button>
            </div>

            @if ($showAdvanced)
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select wire:model.live="linkFollowMode" :label="__('Link follow mode')">
                        <option value="auto">{{ __('Auto') }}</option>
                        <option value="none">{{ __('None') }}</option>
                    </flux:select>

                    <flux:input
                        wire:model.live="linkLimit"
                        :label="__('Link limit')"
                        type="number"
                        min="0"
                        max="20"
                    />

                    <flux:select wire:model.live="crawlRenderer" :label="__('Renderer')">
                        <option value="auto">{{ __('Auto (default)') }}</option>
                        <option value="http">{{ __('HTTP only') }}</option>
                        <option value="playwright">{{ __('Playwright only') }}</option>
                    </flux:select>
                </div>
            @endif

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('Save source') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
