@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $hasEvents = $allDayEvents->isNotEmpty() || $timedEventGroups->isNotEmpty();
    $normalizeText = fn (?string $value): string => Str::of(html_entity_decode($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        ->replace(['\\,'], [','])
        ->replace(['\\n', '\\r', '\\t'], ' ')
        ->squish()
        ->toString();
@endphp

<div class="space-y-6">
    {{-- Page header --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="space-y-0.5">
            <flux:heading size="lg" level="1">{{ __('Calendar') }}</flux:heading>
            <flux:subheading>
                {{ $city ? __('Events for :city', ['city' => $city->name]) : __('Events') }}
            </flux:subheading>
        </div>

        <div class="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
            <flux:icon icon="globe-alt" class="size-3.5 text-zinc-400" />
            {{ $timezone }}
        </div>
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_auto]">
        {{-- Left column: date nav + events --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/60 bg-white shadow-sm">
            {{-- Date navigation bar --}}
            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50/50 px-6 py-4">
                <flux:heading size="md" level="2">{{ $selectedDateLabel }}</flux:heading>

                <div class="flex items-center gap-1">
                    <a
                        href="{{ $previousDateUrl }}"
                        wire:navigate
                        class="inline-grid size-9 place-items-center rounded-lg text-zinc-400 transition hover:bg-zinc-200/60 hover:text-zinc-700"
                        aria-label="{{ __('View previous day') }}"
                    >
                        <flux:icon icon="chevron-left" class="size-4" />
                    </a>
                    <a
                        href="{{ $todayDateUrl }}"
                        wire:navigate
                        class="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-200/60 hover:text-zinc-800"
                    >
                        {{ __('Today') }}
                    </a>
                    <a
                        href="{{ $nextDateUrl }}"
                        wire:navigate
                        class="inline-grid size-9 place-items-center rounded-lg text-zinc-400 transition hover:bg-zinc-200/60 hover:text-zinc-700"
                        aria-label="{{ __('View next day') }}"
                    >
                        <flux:icon icon="chevron-right" class="size-4" />
                    </a>
                </div>
            </div>

            {{-- Event list --}}
            <div class="p-6">
                @if (! $hasEvents)
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="mb-4 grid size-14 place-items-center rounded-2xl bg-zinc-100 text-zinc-400">
                            <flux:icon icon="calendar-days" class="size-7" />
                        </div>
                        <p class="font-medium text-zinc-600">{{ __('Nothing on the schedule') }}</p>
                        <p class="mt-1 text-sm text-zinc-400">{{ __('No events scheduled for this day.') }}</p>
                    </div>
                @else
                    <div class="space-y-4">
                        {{-- All-day events --}}
                        @if ($allDayEvents->isNotEmpty())
                            <div class="space-y-3">
                                <div class="text-[11px] font-semibold uppercase tracking-widest text-zinc-400">{{ __('All day') }}</div>
                                @foreach ($allDayEvents as $event)
                                    @php
                                        $sourceName = $event->sourceItems->first()?->eventSource?->name;
                                        $titleText = $normalizeText($event->title);
                                        $descriptionText = $normalizeText($event->description ? strip_tags($event->description) : null);
                                    @endphp
                                    <div wire:key="all-day-event-{{ $event->id }}" class="group rounded-xl border-l-4 border-l-emerald-400 bg-emerald-50/50 px-5 py-4 transition hover:bg-emerald-50">
                                        <div class="space-y-2">
                                            <h3 class="text-sm font-semibold text-zinc-900">
                                                @if ($event->event_url)
                                                    <a
                                                        href="{{ $event->event_url }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="transition hover:text-emerald-600"
                                                    >
                                                        {{ $titleText }}
                                                    </a>
                                                @else
                                                    {{ $titleText }}
                                                @endif
                                            </h3>
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                                @if ($event->location_name)
                                                    <span class="inline-flex items-center gap-1 font-medium text-emerald-700">
                                                        <flux:icon icon="map-pin" class="size-3" />
                                                        {{ $event->location_name }}
                                                    </span>
                                                @endif
                                                @if ($sourceName)
                                                    <span class="text-zinc-400">{{ Str::limit($sourceName, 36) }}</span>
                                                @endif
                                            </div>
                                            @if ($descriptionText !== '')
                                                <p class="text-sm leading-relaxed text-zinc-600">{{ Str::limit($descriptionText, 200) }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($timedEventGroups as $timeKey => $events)
                            @php
                                $groupTime = $events->first()?->starts_at?->copy()->setTimezone($timezone);
                                $groupLabel = $groupTime?->format('g:i A') ?? $timeKey;
                            @endphp
                            <div class="space-y-3">
                                <div class="text-[11px] font-semibold uppercase tracking-widest text-zinc-400">{{ $groupLabel }}</div>
                                @foreach ($events as $event)
                                    @php
                                        $startsAt = $event->starts_at?->copy()->setTimezone($timezone);
                                        $endsAt = $event->ends_at?->copy()->setTimezone($timezone);
                                        $timeLabel = trim(($startsAt?->format('g:i A') ?? '') . ($endsAt ? ' – ' . $endsAt->format('g:i A') : ''));
                                        $sourceName = $event->sourceItems->first()?->eventSource?->name;
                                        $titleText = $normalizeText($event->title);
                                        $descriptionText = $normalizeText($event->description ? strip_tags($event->description) : null);

                                        $palettes = [
                                            ['border-l-sky-400', 'bg-sky-50/50', 'hover:bg-sky-50', 'text-sky-700'],
                                            ['border-l-violet-400', 'bg-violet-50/50', 'hover:bg-violet-50', 'text-violet-700'],
                                            ['border-l-amber-400', 'bg-amber-50/50', 'hover:bg-amber-50', 'text-amber-700'],
                                            ['border-l-rose-400', 'bg-rose-50/50', 'hover:bg-rose-50', 'text-rose-700'],
                                            ['border-l-teal-400', 'bg-teal-50/50', 'hover:bg-teal-50', 'text-teal-700'],
                                        ];
                                        $palette = $palettes[abs(crc32($titleText)) % count($palettes)];
                                    @endphp
                                    <div wire:key="timed-event-{{ $event->id }}" class="group rounded-xl border-l-4 {{ $palette[0] }} {{ $palette[1] }} px-5 py-4 transition {{ $palette[2] }}">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0 flex-1 space-y-2">
                                                <h3 class="text-sm font-semibold text-zinc-900">
                                                    @if ($event->event_url)
                                                        <a
                                                            href="{{ $event->event_url }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="transition hover:text-emerald-600"
                                                        >
                                                            {{ $titleText }}
                                                        </a>
                                                    @else
                                                        {{ $titleText }}
                                                    @endif
                                                </h3>
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                                    @if ($event->location_name)
                                                        <span class="inline-flex items-center gap-1 font-medium {{ $palette[3] }}">
                                                            <flux:icon icon="map-pin" class="size-3" />
                                                            {{ $event->location_name }}
                                                        </span>
                                                    @endif
                                                    @if ($sourceName)
                                                        <span class="text-zinc-400">{{ Str::limit($sourceName, 36) }}</span>
                                                    @endif
                                                </div>
                                                @if ($descriptionText !== '')
                                                    <p class="text-sm leading-relaxed text-zinc-600">{{ Str::limit($descriptionText, 200) }}</p>
                                                @endif
                                            </div>
                                            @if ($timeLabel)
                                                <span class="shrink-0 rounded-full bg-white/80 px-3 py-1 text-xs font-semibold text-zinc-600 shadow-sm ring-1 ring-zinc-200/60">
                                                    {{ $timeLabel }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Right column: calendar picker --}}
        <div class="h-fit rounded-2xl border border-zinc-200/60 bg-white p-4 shadow-sm">
            <flux:calendar wire:model.live="selectedDate" mode="single" with-today class="w-fit" />
        </div>
    </div>
</div>
