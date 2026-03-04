@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $hasEvents = $allDayEvents->isNotEmpty() || $timedEventGroups->isNotEmpty();
    $normalizeText = fn (?string $value): string => Str::of($value ?? '')
        ->replace(['\\,'], [','])
        ->replace(['\\n', '\\r', '\\t'], ' ')
        ->squish()
        ->toString();
@endphp

<div class="space-y-8">
    <flux:card padding="xl" class="space-y-6 rounded-2xl border border-zinc-200 bg-gradient-to-br from-white via-white to-emerald-50/30 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-2">
                <flux:heading size="lg" level="1">
                    {{ __('Calendar') }}
                </flux:heading>
                <flux:subheading>
                    {{ $city ? __('Events for :city', ['city' => $city->name]) : __('Events') }}
                </flux:subheading>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <div class="rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-700">
                    {{ __('Timezone: :timezone', ['timezone' => $timezone]) }}
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_auto]">
            <section class="space-y-4 px-1 py-1">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 pb-4">
                    <flux:heading size="md" level="2">
                        {{ $selectedDateLabel }}
                    </flux:heading>

                    <div class="flex items-center gap-2">
                        <a
                            href="{{ $previousDateUrl }}"
                            wire:navigate
                            class="inline-grid h-9 w-9 place-items-center rounded-full border border-zinc-200 bg-white text-zinc-500 transition hover:bg-zinc-50 hover:text-zinc-700"
                            aria-label="{{ __('View previous day') }}"
                        >
                            <flux:icon icon="chevron-left" class="size-4" />
                        </a>

                        <a
                            href="{{ $todayDateUrl }}"
                            wire:navigate
                            class="inline-flex h-9 items-center justify-center rounded-full border border-zinc-200 bg-white px-4 text-xs font-semibold leading-none text-zinc-700 transition hover:bg-zinc-50"
                        >
                            {{ __('Today') }}
                        </a>

                        <a
                            href="{{ $nextDateUrl }}"
                            wire:navigate
                            class="inline-grid h-9 w-9 place-items-center rounded-full border border-zinc-200 bg-white text-zinc-500 transition hover:bg-zinc-50 hover:text-zinc-700"
                            aria-label="{{ __('View next day') }}"
                        >
                            <flux:icon icon="chevron-right" class="size-4" />
                        </a>
                    </div>
                </div>

                @if (! $hasEvents)
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-6 text-center">
                        <flux:text>
                            {{ __('No events scheduled for this day.') }}
                        </flux:text>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($allDayEvents as $event)
                            @php
                                $startsAt = $event->starts_at?->copy()->shiftTimezone($timezone);
                                $endsAt = $event->ends_at?->copy()->shiftTimezone($timezone);
                                $timeLabel = $event->all_day
                                    ? __('All day')
                                    : trim(($startsAt?->format('g:i A') ?? '').($endsAt ? ' - '.$endsAt->format('g:i A') : ''));
                                $sourceName = $event->sourceItems->first()?->eventSource?->name;
                                $titleText = $normalizeText($event->title);
                                $descriptionText = $normalizeText($event->description ? strip_tags($event->description) : null);
                            @endphp

                            <flux:card wire:key="all-day-event-{{ $event->id }}" padding="lg" class="space-y-3 rounded-xl border border-zinc-200/70 bg-zinc-50/40">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <flux:heading size="md" level="3" class="min-w-0">
                                        @if ($event->event_url)
                                            <a href="{{ $event->event_url }}" class="hover:underline">
                                                {{ $titleText }}
                                            </a>
                                        @else
                                            {{ $titleText }}
                                        @endif
                                    </flux:heading>

                                    @if ($timeLabel)
                                        <div class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                            {{ $timeLabel }}
                                        </div>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($event->location_name)
                                        <flux:badge color="sky" variant="subtle">
                                            {{ $event->location_name }}
                                        </flux:badge>
                                    @endif

                                    @if ($sourceName)
                                        <flux:badge color="zinc" variant="subtle">
                                            {{ Str::limit($sourceName, 36) }}
                                        </flux:badge>
                                    @endif
                                </div>

                                @if ($descriptionText !== '')
                                    <flux:text>
                                        {{ Str::limit($descriptionText, 180) }}
                                    </flux:text>
                                @endif
                            </flux:card>
                        @endforeach

                        @foreach ($timedEventGroups as $events)
                            @foreach ($events as $event)
                                @php
                                    $startsAt = $event->starts_at?->copy()->shiftTimezone($timezone);
                                    $endsAt = $event->ends_at?->copy()->shiftTimezone($timezone);
                                    $timeLabel = $event->all_day
                                        ? __('All day')
                                        : trim(($startsAt?->format('g:i A') ?? '').($endsAt ? ' - '.$endsAt->format('g:i A') : ''));
                                    $sourceName = $event->sourceItems->first()?->eventSource?->name;
                                    $titleText = $normalizeText($event->title);
                                    $descriptionText = $normalizeText($event->description ? strip_tags($event->description) : null);
                                @endphp

                                <flux:card wire:key="timed-event-{{ $event->id }}" padding="lg" class="space-y-3 rounded-xl border border-zinc-200/70 bg-zinc-50/40">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <flux:heading size="md" level="3" class="min-w-0">
                                            @if ($event->event_url)
                                                <a href="{{ $event->event_url }}" class="hover:underline">
                                                    {{ $titleText }}
                                                </a>
                                            @else
                                                {{ $titleText }}
                                            @endif
                                        </flux:heading>

                                        @if ($timeLabel)
                                            <div class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                                {{ $timeLabel }}
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($event->location_name)
                                            <flux:badge color="sky" variant="subtle">
                                                {{ $event->location_name }}
                                            </flux:badge>
                                        @endif

                                        @if ($sourceName)
                                            <flux:badge color="zinc" variant="subtle">
                                                {{ Str::limit($sourceName, 36) }}
                                            </flux:badge>
                                        @endif
                                    </div>

                                    @if ($descriptionText !== '')
                                        <flux:text>
                                            {{ Str::limit($descriptionText, 180) }}
                                        </flux:text>
                                    @endif
                                </flux:card>
                            @endforeach
                        @endforeach
                    </div>
                @endif
            </section>

            <flux:card padding="md" class="h-fit rounded-2xl border border-zinc-200 bg-white shadow-sm">
                <flux:calendar wire:model.live="selectedDate" mode="single" with-today class="w-fit" />
            </flux:card>
        </div>
    </flux:card>
</div>
