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

<div class="space-y-6 lg:space-y-8">
    {{-- Page header --}}
    <div class="public-masthead flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="editorial-eyebrow">{{ __('Community calendar') }}</div>
            <h1 class="mt-3 font-serif text-4xl font-medium tracking-[-0.035em] text-[#123e32] sm:text-5xl">{{ __('Calendar') }}</h1>
            <p class="mt-3 text-sm text-[#5d7168] sm:text-base">
                {{ $city ? __('Meetings and events for :city', ['city' => $city->name]) : __('Local meetings and events') }}
            </p>
        </div>

        <div class="flex items-center gap-1.5 text-xs font-semibold text-[#667970]">
            <flux:icon icon="globe-alt" class="size-3.5" />
            {{ $timezone }}
        </div>
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_auto]">
        {{-- Left column: date nav + events --}}
        <div class="public-panel overflow-hidden">
            {{-- Date navigation bar --}}
            <div class="flex items-center justify-between border-b border-[#e4e2da] bg-[#faf9f5] px-5 py-4 sm:px-6">
                <flux:heading size="md" level="2" class="font-serif !text-xl !font-medium text-[#18342c]">{{ $selectedDateLabel }}</flux:heading>

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
                                    <div wire:key="all-day-event-{{ $event->id }}" class="group border-l-2 border-l-[#1f654f] bg-[#f5f8f6] px-5 py-4 transition hover:bg-[#eef4f0]">
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

                                    @endphp
                                    <div wire:key="timed-event-{{ $event->id }}" class="group border-l-2 border-l-[#7da996] bg-[#fafbf9] px-5 py-4 transition hover:bg-[#f3f6f3]">
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
                                                        <span class="inline-flex items-center gap-1 font-medium text-[#1f654f]">
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
                                                <span class="shrink-0 border-l border-[#d9d7ce] pl-3 text-xs font-semibold text-[#496159]">
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
        <div class="public-panel h-fit p-4 xl:sticky xl:top-28">
            <flux:calendar wire:model.live="selectedDate" mode="single" with-today class="w-fit" />
        </div>
    </div>
</div>
