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

<div class="flex flex-col gap-8">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">
            {{ __('Calendar') }}
        </flux:heading>
        <flux:text variant="subtle">
            {{ $city ? __('Events for :city', ['city' => $city->name]) : __('Events') }}
        </flux:text>
    </div>

    <div class="flex flex-col gap-6">
        <div class="grid gap-6 lg:grid-cols-3 lg:gap-14">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <flux:heading size="lg" level="2">
                    {{ $selectedDateLabel }}
                </flux:heading>

                @if (! $hasEvents)
                    <flux:card padding="lg">
                        <flux:text>
                            {{ __('No events scheduled for this day.') }}
                        </flux:text>
                    </flux:card>
                @else
                    <div class="flex flex-col gap-6">
                        @if ($allDayEvents->isNotEmpty())
                            <div class="flex flex-col gap-3">
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

                                    <flux:card wire:key="all-day-event-{{ $event->id }}" padding="lg" class="flex flex-col gap-3">
                                        <div class="flex flex-col gap-2">
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
                                                    <flux:heading size="sm" level="4" class="whitespace-nowrap">
                                                        {{ $timeLabel }}
                                                    </flux:heading>
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
                                        </div>
                                    </flux:card>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-col gap-3">
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

                                    <flux:card wire:key="timed-event-{{ $event->id }}" padding="lg" class="flex flex-col gap-3">
                                        <div class="flex flex-col gap-2">
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
                                                    <flux:heading size="sm" level="4" class="whitespace-nowrap">
                                                        {{ $timeLabel }}
                                                    </flux:heading>
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
                                    </div>
                                </flux:card>
                            @endforeach
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="inline-flex w-fit max-w-full">
                <flux:calendar wire:model.live="selectedDate" mode="single" with-today class="w-fit" />
            </div>
        </div>
    </div>
</div>
