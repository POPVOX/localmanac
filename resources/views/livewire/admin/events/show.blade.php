@php
    $tz = $event->city?->timezone ?? config('app.timezone', 'UTC');
    $sourceItem = $event->sourceItems->first();
    $source = $sourceItem?->eventSource;
    $startsAt = $event->starts_at?->clone()->shiftTimezone($tz);
    $endsAt = $event->ends_at?->clone()->shiftTimezone($tz);
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">{{ $event->title ?: __('Event :id', ['id' => $event->id]) }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <flux:badge color="indigo" variant="subtle">
                    {{ $source?->name ?? __('Unknown source') }}
                </flux:badge>
                <flux:badge color="zinc" variant="subtle">
                    {{ $event->city?->name ?? __('Unknown city') }}
                </flux:badge>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" :href="route('admin.events.index')" wire:navigate>
                {{ __('Back to events') }}
            </flux:button>
            <flux:button variant="ghost" :href="route('admin.events.edit', $event)" wire:navigate>
                {{ __('Edit') }}
            </flux:button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card padding="xl" variant="subtle" class="space-y-3">
            <flux:heading size="lg">{{ __('When') }}</flux:heading>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Starts') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $startsAt ? $startsAt->format('M j, Y g:i A') : __('—') }}
                </div>
            </div>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Ends') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $endsAt ? $endsAt->format('M j, Y g:i A') : __('—') }}
                </div>
            </div>
            <div>
                @if ($event->all_day)
                    <flux:badge color="amber" variant="subtle">{{ __('All day') }}</flux:badge>
                @else
                    <flux:text variant="subtle">{{ __('Timed event') }}</flux:text>
                @endif
            </div>
        </flux:card>

        <flux:card padding="xl" variant="subtle" class="space-y-3">
            <flux:heading size="lg">{{ __('Where') }}</flux:heading>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Location') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $event->location_name ?: __('—') }}
                </div>
            </div>
            <div class="space-y-1">
                <flux:text variant="subtle">{{ __('Address') }}</flux:text>
                <div class="font-medium text-zinc-900 dark:text-white">
                    {{ $event->location_address ?: __('—') }}
                </div>
            </div>
        </flux:card>

        <flux:card padding="xl" variant="subtle" class="space-y-3">
            <flux:heading size="lg">{{ __('External link') }}</flux:heading>
            @if ($event->event_url)
                <flux:link href="{{ $event->event_url }}" target="_blank" class="break-all text-sm">
                    {{ $event->event_url }}
                </flux:link>
                <flux:button size="sm" variant="primary" href="{{ $event->event_url }}" target="_blank">
                    {{ __('Open event') }}
                </flux:button>
            @else
                <flux:text variant="subtle">{{ __('No external link available.') }}</flux:text>
            @endif
        </flux:card>
    </div>

    <flux:card padding="xl" variant="subtle" class="space-y-3">
        <flux:heading size="lg">{{ __('Description') }}</flux:heading>
        @if ($descriptionPreview !== '')
            <div class="whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-200">
                {{ $descriptionPreview }}
            </div>
        @else
            <flux:text variant="subtle">{{ __('No description available.') }}</flux:text>
        @endif
    </flux:card>

    <flux:card padding="xl" variant="subtle">
        <details class="space-y-4">
            <summary class="cursor-pointer text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                {{ __('Debug & trace') }}
            </summary>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Source hash') }}</flux:text>
                    <div class="text-sm font-mono text-zinc-900 dark:text-white break-all">
                        {{ $event->source_hash ?: __('—') }}
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Source item') }}</flux:text>
                    <div class="text-sm text-zinc-900 dark:text-white">
                        {{ $sourceItem?->id ? __('Item #:id', ['id' => $sourceItem->id]) : __('—') }}
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Created at') }}</flux:text>
                    <div class="text-sm text-zinc-900 dark:text-white">
                        {{ $event->created_at?->clone()->tz($tz)->format('M j, Y g:i A') ?? __('—') }}
                    </div>
                </div>
                <div class="space-y-1">
                    <flux:text variant="subtle">{{ __('Updated at') }}</flux:text>
                    <div class="text-sm text-zinc-900 dark:text-white">
                        {{ $event->updated_at?->clone()->tz($tz)->format('M j, Y g:i A') ?? __('—') }}
                    </div>
                </div>
            </div>

            @if ($rawPayloadPreview !== '')
                <div class="space-y-2">
                    <flux:text variant="subtle">{{ __('Raw payload') }}</flux:text>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs font-mono leading-relaxed dark:border-zinc-700 dark:bg-zinc-800">
                        <pre class="whitespace-pre-wrap break-words">{{ $rawPayloadPreview }}</pre>
                    </div>
                </div>
            @else
                <flux:text variant="subtle">{{ __('No raw payload available.') }}</flux:text>
            @endif
        </details>
    </flux:card>
</div>
